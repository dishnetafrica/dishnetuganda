# Change control

The UISP/uCRM installation is treated as production infrastructure that happens
to share a host with EasyPanel. This page is the standing rule set.

## The one way this setup gets broken

The whole arrangement depends on one fact: **UISP was installed with
non-default ports (8080/8443), so Traefik can hold 80/443.**

Re-running the UISP installer without the original port flags would make UISP
try to bind 80 and 443. Traefik already holds them, so UISP fails to start, and
depending on ordering after a reboot the two can fight over the ports. That is
the single most likely cause of a serious outage here.

So:

- **Use `unms-cli update` to update UISP.** It preserves the configured ports.
- **Never re-run the UISP install script** as a way of fixing a problem.
- Before any UISP maintenance, record the current ports from `unms.conf` (the
  inspection report captures them) so they can be re-supplied if an install
  ever genuinely becomes necessary.

## Standing rules

Per your instruction, and with the reason each one exists:

| Rule | Why |
| --- | --- |
| Do not install or recreate UISP inside EasyPanel | EasyPanel would own the container lifecycle; a panel upgrade could then take the network offline |
| Do not update UISP containers through EasyPanel or `docker pull` | UISP updates involve database migrations that `unms-cli update` sequences correctly and an image swap does not |
| Do not move UISP behind EasyPanel for device traffic | Device connectivity would inherit Traefik's uptime. Browser UI through Traefik is fine — see `05` |
| Do not replace or reinstall the existing UISP | Above |
| Use EasyPanel for everything *except* UISP | That is what it is good at |

## Procedure for any change

1. **Establish the baseline.** `sudo ./scripts/verify-uisp-health.sh` — save it.
2. **Snapshot.** `sudo ./scripts/backup-configs.sh`, plus a UISP backup from the
   UI if the change touches UISP at all.
3. **Write it down first**, in the five-part form below.
4. **Apply one change.** Not three.
5. **Verify.** `verify-uisp-health.sh` again, plus a real device check if
   anything touched ports, DNS or the UISP hostname.
6. **Commit** the documentation change to this repo, so the repo stays an
   accurate record of the server.

If step 5 does not match step 1, roll back. Do not investigate forward from a
degraded state on a live network.

## The five-part form

Every change gets these written down before it is applied:

1. **What is currently configured** — observed, from the inspection report.
2. **Why the change is necessary** — the problem, not the solution.
3. **Exactly what will change** — files, ports, settings, commands, verbatim.
4. **Effect on UISP/uCRM** — including "none", with the reasoning for why none.
5. **Rollback** — the specific steps, and how long they take.

[05-domain-and-tls-plan.md](05-domain-and-tls-plan.md) is written in this form
as a worked example.

## Change log

| Date | Change | Applied by | Rollback | Status |
| --- | --- | --- | --- | --- |
| — | Repo created: inspection tooling and plans. No server changes. | Claude | n/a | Documentation only |
| 2026-09-14 | Sent-folder archive routed to `stalwart` with verification off (`set_sent_copy.php --host stalwart --hosts ''`). Workaround for Stalwart's placeholder certificate. See [09](09-mail-and-pdf-delivery.md). | Bhavin | `--host mail.dishnetuganda.com --hosts stalwart`, seconds | Applied, verified by `--test` |
| 2026-09-14 | `crm_public_url` written into `kyc_config.json` so `webhook.php` can see it, moving quotation PDF URLs off `:8443`. Workaround for the webhook's single-source config read. See [09](09-mail-and-pdf-delivery.md). | Bhavin | `saveOverrides(..., ['crm_public_url' => ''])`, seconds | Confirmed by a real send 15 Sep; **retired** later that day once 5.17.0 fixed the root cause — the duplicate was cleared and the value lives only in `config.json` |
| 2026-09-15 | Plugin 5.17.0 uploaded via uCRM → Settings → Plugins. `webhook.php` now overlays `PluginConfig::load()` onto the store copy (root cause of the 14 Sep PDF fault); quotation emails write a `customer_email_log` row with sender; `SentCopy` distinguishes TLS failure from unreachability. Code in `059acf9`; record in [09](09-mail-and-pdf-delivery.md). | Bhavin | Re-upload the 5.16.0 ZIP (retained from 14 Sep), minutes | Applied; verified `grep -c "+ PluginConfig::load" webhook.php` = 1 in the container |
| 2026-09-15 | Plugin 5.18.0 built for upload via uCRM → Settings → Plugins. Quotation PDF links are signed and checked only by the daily-rotating token (`lib/QuotePdfToken.php`: today and yesterday, UTC); `serve_quote_pdf` no longer accepts the permanent `.meta` token and serves `.pdf` files only; failed-queue retries re-sign a stale link; `tools/notify_doctor.php` reports whether `webhook_secret` is set in the store. Receipt, delivery-note and temporary-invoice links unchanged. Code in `4d432be`; record in [09](09-mail-and-pdf-delivery.md). | Bhavin | Re-upload the 5.17.0 ZIP (retained from 15 Sep), minutes | Applied 15 Sep; verified in the container: `grep -c "QuotePdfToken::verify" includes/api/api_cron_debug.php` = 1, `manifest.json` at 5.18.0. `notify_doctor` then reported `quote PDF link secret: DEFAULT` — `webhook_secret` is unset in the store, so quotation links are signed with the published default. **Open**, see [09](09-mail-and-pdf-delivery.md). |
| 2026-09-15 | Plugin 5.18.1 built for upload via uCRM → Settings → Plugins. Quotation PDF links sign with a key of their own, `quote_pdf_secret`, generated once into the store copy of `kyc_config.json` at the first plugin page load, direct webhook hit or quote-cron run that finds none; `webhook_secret` is left alone (it also derives the customer-app JWT key and acts as the `debug_key` bearer). `notify_doctor` line now three-state. Code in `822ffc2`; record in [09](09-mail-and-pdf-delivery.md). | Bhavin | Re-upload the 5.18.0 ZIP (retained from 15 Sep), minutes; links then fall back to the shared secret or default as before | Applied 15 Sep; verified in the container: `manifest.json` at 5.18.1; the key was generated by running the plugin's own `QuotePdfToken::ensureSecret()` from the CLI before any plugin page had loaded; `notify_doctor` then read `set — quote_pdf_secret in the store, shared with nothing else`; the live `serve_quote_pdf` endpoint answered 200 to a link signed with the new key and 403 to one signed with the old default |
| 2026-09-15 | Plugin 5.18.2 built for upload via uCRM → Settings → Plugins. Settings → "Setup Webhook", the `webhook_register` debug action and `tools/webhook_setup.php` now share one policy (`lib/WebhookRegistrar.php`): create for every event at an address uCRM can reach, otherwise repair only what is wrong; never narrow the event list, never replace a reachable address, never touch `webhook_secret`. The Settings tab judges the route and shows an events tile. Code in `87e00ca`; record in [09](09-mail-and-pdf-delivery.md). | Bhavin | Re-upload the 5.18.1 ZIP (retained from 15 Sep), minutes | **Pending upload**; verify: Settings → Webhook tab shows "Route OK" and "Events OK" for the existing endpoint, and pressing "Setup Webhook" reports that nothing changed |
| 2026-09-15 | Plugin 5.18.3 built for upload via uCRM → Settings → Plugins. The sales assistant now sees a prospect's own recent conversation (`unknown` identity replays its own epoch, 14-day floor; customers' turns still never reach it) and carries a prospect-selling posture: answer price requests first, treat a typed name/company/email as progress, acknowledge a colleague named or a call referred to, escalate, never impersonate. Lead records carry the email. Worker logs `identity=… history=…` per turn. Code in `b5bcbcc`; record in [10](10-ai-sales-prospects.md). | Bhavin | Re-upload the 5.18.2 ZIP (retained from 15 Sep), minutes | Applied 15 Sep; `manifest.json` at 5.18.3 verified in the container; all four assistant switches on, human cooldown 30 min. Behaviour to be confirmed on the next prospect conversation: the log shows `history=` rising turn by turn and the replies build on the thread |
| 2026-09-15 | Plugin 5.18.4 built for upload via uCRM → Settings → Plugins. A customer created in billing mid-conversation keeps the prospect thread (previous `unknown` epoch carried forward, 14-day window, one direction only); an identified customer with no live service gets a sign-up posture instead of service mode; `NotificationService::send()` sends the caller's text or nothing (no more "EVENT CLIENT ADD" dumps; hand-created clients get a real welcome); knowledge seed `PLAN_SERVICE_MAP` rewritten to use live plan names. Code in `8c8deb0`; record in [10](10-ai-sales-prospects.md). | Bhavin | Re-upload the 5.18.3 ZIP, minutes | Applied 15 Sep. `seed_knowledge.php --refresh-seeded` in the container reported `corrected: PLAN_SERVICE_MAP` (0 added, 34 present, 1 corrected), which also confirms the 5.18.4 files are the ones installed. Behaviour to be confirmed on the next prospect who is created in billing mid-conversation |
| 2026-09-15 | Plugin 5.18.5 built for upload via uCRM → Settings → Plugins. A uCRM product named like a monthly plan (the two plan mirrors in the Products tab, used for quotation lines) is dropped from the assistant's one-time HARDWARE list, so a plan is never presented as a one-off or added into a connection total; the drop is logged. Code in `1aa3822`; record in [10](10-ai-sales-prospects.md). | Bhavin | Re-upload the 5.18.4 ZIP, minutes | **Applied via 5.18.6, 15 Sep 2026.** Verified: the catalogue listing shows HARDWARE as the two kits and the installation only; both plan mirrors gone |
| 2026-09-15 | Plugin 5.18.6 built for upload via uCRM → Settings → Plugins. The invoice e-mail now carries the invoice: the `invoice.add` webhook fetches uCRM's invoice PDF once and hands the same bytes to the WhatsApp document and to the e-mail as `Invoice-<number>.pdf`; it passes the plan and service period the template actually reads (the first wiring passed a key the template ignored), greets by first name and writes the due date out; the template says "PDF attached" only when one is, and the same rule now governs the quotation e-mail on all three of its senders. Plain-text parts of every template list no fact they do not have (the receipt printed "Service period:" with nothing after it). Includes 5.18.5. Code in `ab27406`; record in [09](09-mail-and-pdf-delivery.md). | Bhavin | Re-upload the 5.18.4 ZIP, minutes | **Applied 15 Sep 2026.** Verified: manifest reads 5.18.6 (grep count 1); catalogue listing clean. The invoice switch was turned on the same day (`--on invoice`), so quotation, payment receipt and invoice now e-mail customers; uCRM's own Mailer / Notifications pages still unchecked in the browser. Still to observe: the first real invoice landing in `customer_email_log` as `invoice · sent` with `Invoice-<number>.pdf` in the mailbox |
| 2026-09-15 | Plugin 5.18.7 built for upload via uCRM → Settings → Plugins. The five lifecycle e-mails not yet switched on (welcome, service paused, service resumed, installation scheduled, support received) are wired to print their facts: each had passed the template keys it does not read, two subjects would have gone out half-written, and two fired on events that do not mean what the e-mail says. Now: service facts from uCRM's own records in its own offset; the paused e-mail names the unpaid invoice and amount and offers the configured pay link; a pause is remembered per service so `service.activate` sends the resumption after a pause and the welcome for a first activation, claiming a payment only when one was seen; installation e-mails need an installation title, a date and a client; support acknowledgements need a client. Code in `199b421`; record in [09](09-mail-and-pdf-delivery.md). | Bhavin | Re-upload the 5.18.6 ZIP, minutes | **Applied 15 Sep 2026.** Verified: manifest reads 5.18.7 (grep count 1) and the switch tool shows the new "fires when" texts. All eight lifecycle e-mails switched on the same day. Still to observe: the first real welcome, paused, resumed, installation and support sends in `customer_email_log`; uCRM's own Mailer / Notifications pages still unchecked in the browser |
| 2026-09-15 | Plugin 5.18.8 built for upload via uCRM → Settings → Plugins. `tools/set_customer_emails.php --all-off` cleared every e-mail switch but displayed all eight still ON: the dispatcher's disk snapshot was a one-shot static filled by the "Before" display. The snapshot now follows the files, and the tool compares what it reads back with what it was asked, printing FAILED and exiting non-zero when something else (data/config.json) still supplies a value. New `tools/wa_lifecycle_test.php`: every customer WhatsApp message the notification service composes, with invented data, to one number, between a TEST header and footer, with `--dry-run` and `--only`. Code in `1661531`; record in [09](09-mail-and-pdf-delivery.md). | Bhavin | Re-upload the 5.18.7 ZIP, minutes | **Applied 15 Sep 2026.** Verified: manifest reads 5.18.8 (grep count 1); `--show` reported the true state (all eight OFF since the earlier `--all-off`); all eight switched back on in one command with a truthful read-back. `email_lifecycle_test.php`: 9 of 9 [TEST] e-mails sent and filed in Sent Items. `wa_lifecycle_test.php`: header, 10 samples and footer all delivered (HTTP 200) to the operator's number |
| 2026-09-15 | Plugin 5.18.9 built for upload via uCRM → Settings → Plugins. The WhatsApp assistant stays awake: the WhatsApp Business app's greeting (a text our side sent word for word to 3+ other conversations in 7 days) is stored as `WhatsApp auto-reply` and never stands the AI down — it had done so in 49 conversations in a week; a question arriving during a colleague's pause is parked (`EventBus::defer()`, attempts untouched) and answered when the pause ends unless a person answered it, dropped after `wa_parked_max_minutes` (120); photo, document and flyer sends claim and record their Evolution id like the text reply; stored UTC stamps are read as UTC on both worker clocks (`lib/UtcClock.php`) in the hand-over check, the watchdog and the queue's retry times; a message whose five retries fail pages the team and sends the customer the hand-over line; `tools/wa_clock_repair.php` corrects the 36 notification rows stamped three hours ahead. Code in `c8e3ab6`; record in [17](17-whatsapp-bot-availability.md). | Bhavin | Re-upload the 5.18.8 ZIP, minutes | **Applied 16 Sep 2026.** Verified: manifest reads 5.18.9 (grep count 1). `wa_clock_repair.php` reported 41 rows in 18 conversations, all written by the notification record (`DishNet Plugin`), confirming the diagnosis; `--fix` repaired 41, remaining 0. Log baseline at install: `human active, skipping AI` 208 (must not grow); the four replacement lines 0. Still to do by the operator: switch off the WhatsApp Business Greeting/Away messages on both numbers; set `wa_human_cooldown_minutes` deliberately (5–30); confirm `ai_handover_message` is set |
| 2026-09-16 | Plugin 5.18.10 built for upload via uCRM → Settings → Plugins. A prospect on the support number was quoted a kit, an installation, a total and two monthly plans, none of them in uCRM: that number was never handed the catalogue (fetched for sales only), nothing in its prompt said the list was missing, and `ReplyPrivacyGuard` — which refuses money the tools did not return — was wired into the old WASender webhook and never into the Evolution worker. Now the catalogue loads on support and account whenever `ai_sales_on_all_numbers` is on; the PLANS/HARDWARE absence fences appear on every channel; the guard runs on every reply the worker sends, permitting catalogue prices, sums of one-time items, whole-month plan multiples, the customer's own words and figures already in the prompt — anything else with money on it is replaced by the safe fallback, handed over and recorded as metadata. Code in `0e39c4f`; record in [18](18-prices-come-from-ucrm.md). | Bhavin | Re-upload the 5.18.9 ZIP, minutes | **Applied 16 Sep 2026.** Verified: manifest reads 5.18.10 (grep count 1). Baseline at install: 0 blocks, 0 support-channel turns with the catalogue (no support message had arrived since the upload). `build.json` is absent on this install by design — the stamp ships from 5.18.11. Still to observe: the first support-channel turn showing `catalogue loaded`; `ai_security_events.json` for any block with a legitimate reply behind it |
| 2026-09-16 | Plugin 5.18.11 built for upload via uCRM → Settings → Plugins. Accessories shop, part 1: `tools/shop_products_sync.php` creates the twenty accessories in uCRM Products by exact name (report first; `--apply --prices-include-tax`; tax copied from the kit; never edits an existing product); `assets/shop/catalogue.json` holds content and photos and no price; `public.php?page=shop` lists kits and accessories at uCRM prices through the price feed's cache with an Order-on-WhatsApp button, `?page=shop_img` serves sized WebP photos by slug; the assistant gets accessories under an ACCESSORIES heading apart from the kit and installation, and a `ai_fact_prices` business fact for the VAT statement; the price guard permits kit + installation + one or two accessories. First ZIP carrying `build.json`. Code in this commit; record in [20](20-accessories-shop-part-1.md). | Bhavin | Re-upload the 5.18.10 ZIP, minutes; delete the twenty products in uCRM if they must go | **Applied 16 Sep 2026.** Manifest 5.18.11 verified. The sync tool's report was right (5 uCRM products, 20 to create, kit spelling `Starlink Mini Kit`), but `--apply` created nothing: uCRM refused every product with 422 because the payload carried a `description` field the product record does not have. `set_config.php` refused `ai_fact_prices` as unmanaged. Both corrected in 5.18.12 below |
| 2026-09-16 | Plugin 5.18.12 built for upload via uCRM → Settings → Plugins. Corrects two things the 5.18.11 live run showed: the sync tool sends only uCRM's product fields (`name`, `unit`, `price`, `taxable`, `taxId` copied from the kit; no `description`), prints the exact product names uCRM holds, and the fake uCRM in the tests now refuses unknown fields like the real one; `tools/set_config.php` manages `ai_fact_prices`. Nothing else changes. Code in this commit; record in [20](20-accessories-shop-part-1.md). | Bhavin | Re-upload the 5.18.11 ZIP, minutes; the twenty products, once created, are deleted in uCRM Products if they must go | **Applied 16 Sep 2026.** `build.json` confirms commit `3a33735` on disk (the 5.18.11 attempt had not been uploaded; the stamp is what proved it). Sync: `created 20 · failed 0`, uCRM product ids 8–27. `ai_fact_prices` set to "All our listed prices include VAT." The report names the five pre-existing products: `Starlink Mini Kit`, `Starlink Standard Kit`, `Professional Installation`, `Residential (up to 400 Mbps)`, `Residential Lite (up to 100 Mbps)` — both kit spellings match the shop catalogue, so both kit cards will show. **Open question for the operator:** `Starlink Mini Kit` is `taxable: no` in uCRM, so the twenty accessories copied that. Prices are therefore final as listed and no invoice line carries VAT — consistent with the kits, but a question for the accountant if these supplies are VAT-taxable. Still to observe: the shop page at `?page=shop`, and two test chats on the sales number |
| 2026-09-16 | Plugin 5.18.13 + website. **Regression fixed:** creating the twenty accessories put them into the public price feed's `hardware` list, which `starlink-kits.html` renders as kit cards — the live kits page showed twenty mounts and cables as Starlink kits, each promising delivery, installation and a first month. The feed now returns `plans`, `hardware` (kits and installation) and `accessories` (with photo slugs), and drops products that are really plan mirrors; `prices.js` limits the kits grid to kits. **New:** `dishnetuganda.com/shop.html` with `assets/js/shop.js` rendering the live catalogue, a Shop link in the desktop and mobile nav on all 36 pages, a sitemap entry, and the fallback card if the feed is unreachable. `verify-site.sh` PASSES (57 pages, 0 broken references, no price published in the repository). Code in this commit; record in [21](21-shop-on-the-website.md). | Bhavin | Re-upload the 5.18.12 ZIP; redeploy the website container | **Plugin applied 16 Sep 2026.** Manifest 5.18.13 verified. Feed confirmed after clearing the price cache: `hardware` 3 (`Professional Installation`, `Starlink Mini Kit`, `Starlink Standard Kit`) and `accessories` 20 — the kits page renders kits again and the plan mirrors are gone from both one-time lists. **Website deployed 16 Sep 2026** and confirmed working by the operator: `dishnetuganda.com/shop.html` is live with the Shop tab in the navigation. Note for the next website change: the shop work sits on `claude/study-this-jhe2eg`, which was 93 commits ahead of `main` at the time — whatever branch the EasyPanel app builds from must carry it |
| 2026-09-17 | Plugin 5.18.14 built for upload via uCRM → Settings → Plugins. A customer ready to buy asked which number to pay to at 00:59 and was refused: that is the South Sudan default of the `ai_fact_payment` business fact ("NEVER share bank details"), and the key could not be set from anywhere — nor could `ai_fact_office` or `ai_fact_delivery`, so Ugandan customers are still told the office is in Juba and kits cross at the Joda border. All three are now managed by `tools/set_config.php`, with a warning when a payment text carries an account number and when an office or delivery text names a South Sudan place. A set payment fact is quoted verbatim by the assistant (never reformatted), because `ReplyPrivacyGuard` permits only figures the prompt contains: the configured account is sent, any other is blocked with the safe fallback and a hand-over. Prompt corpus unchanged. Code in this commit; record in [22](22-payment-details-in-chat.md). | Bhavin | Re-upload the 5.18.13 ZIP; clear the setting with `--clear` to return to the refusal | **Applied 17 Sep 2026.** `ai_fact_office` (Acacia Mall, Kampala) and `ai_fact_delivery` (the team delivers and installs) set correctly. `ai_fact_payment` was set from the example command in this record **with its `<BANK>` / `<NUMBER>` placeholders still in it**, so for a few hours the assistant was ready to tell a customer to pay into "account <NUMBER>" — a worse answer than the refusal it replaced, and my fault for publishing a runnable command that was really a template. Corrected the same day with the real Ecobank UGX account, and 5.18.15 below makes the tool refuse the template |
| 2026-09-17 | Plugin 5.18.15 built for upload via uCRM → Settings → Plugins. `tools/set_config.php` refuses any value still carrying an angle-bracketed placeholder (`<BANK>`, `<NUMBER>`, `<TILL>`) and saves nothing, naming the placeholder it found and offering `--clear` as the way back. It exists because the 5.18.14 example command was pasted verbatim into live config: a deploy note that can be run is run, so the tool now has to catch what the notes cannot. Checked before any write, on every key, not just the payment fact. Nothing else changes; prompt corpus unchanged. Code in this commit; record in [22](22-payment-details-in-chat.md). | Bhavin | Re-upload the 5.18.14 ZIP, minutes; the refusal is a check, not a stored setting, so nothing is left behind | Pending upload. After upload, a value containing `<` + capitals is refused with "nothing was saved" and the previous value stands |
| 2026-09-17 | Plugin 5.18.16 built for upload via uCRM → Settings → Plugins. **The payment fact could not actually be delivered.** The prompt orders it repeated character for character; `ReplyPrivacyGuard`'s leak rule discards any 45-character run of the prompt echoed back in a reply — so the more exactly the assistant answered "which account do I pay into?", the more certainly the reply was replaced by the safe fallback and handed to a person. The same applied to the office and delivery facts. Found by testing the live configured value against the brain's real prompt. `DishNetAiBrain::operatorText()` now returns the business facts the operator actually set, and both reply paths pass them to the guard as `public`: a prompt sentence that came from the operator's own text is an answer, not a leak. Built-in defaults are never quotable (they carry instructions), nor are the sentences the plugin wraps around the operator's text. Swept over every one of the 115 qualifying sentences of the real prompt: 3 operator sentences sent, 112 of ours still refused. Prompt corpus unchanged. Code in this commit; record in [22](22-payment-details-in-chat.md). | Bhavin | Re-upload the 5.18.15 ZIP, minutes; or `--clear` the payment fact, which returns to the refusal | Pending upload. After upload, the live check is one message on the sales number asking where to pay: the reply must carry the account number, and `ai_security_events.json` must gain no `system_prompt` block behind it |
| 2026-09-17 | Plugin 5.18.17 built for upload via uCRM → Settings → Plugins. **A customer was sent the `<<LEAD {...}>>` marker**, their own name and location as JSON, on the end of an otherwise good reply: the model closed the marker with one angle bracket instead of two, every pattern required two, so nothing was stripped — and the sales lead was never recorded either. The LEAD JSON is now walked brace by brace (strings and escapes respected, so a `}` or `>` in a value cannot end it early) and however many closing brackets the model wrote, including none, are consumed; unterminated JSON is cut rather than displayed; `ESCALATE`, `QUOTE`, `FLYER`, `PHOTO` and `DOC` accept one bracket and still act on their intent; and a net underneath removes any run opening with `<<` that names one of our six markers, matching those names only. Prompt corpus unchanged. Code in this commit; record in [23](23-the-marker-that-reached-a-customer.md). | Bhavin | Re-upload the 5.18.16 ZIP, minutes | Pending upload. The lead from that conversation was lost and is not recoverable from the queue — it is in the WhatsApp thread only, so it needs entering by hand if it is still wanted |
| 2026-09-18 | Plugin 5.18.18 built for upload via uCRM → Settings → Plugins. **Phase 1 of 3 — location.** A customer answering "which area?" with a WhatsApp location pin got no reply at all: `evoExtractText()` knew eight message shapes and `locationMessage` was not one, so the text came out empty and the queue step dropped the message; the coordinates were never read out of the payload. New `lib/WaLocation.php` parses a pin, refuses `0,0` and anything off the globe, and keeps but flags a pin outside the deployment country (derived from `timezone`, overridable with `geo_country`); `WaLocation::mergeText()` gives the AI something to answer so the message is queued; migration 072 stores `location_lat`/`location_lng` on `wa_messages` and the inbox body shows the coordinates; the worker attaches the conversation's most recent pin to the lead it writes, while a coordinate the MODEL supplies can never be written (not in `FIELDS`); the assistant is told it has no map and must not name a town, estimate distance, or judge coverage. `storeMessage()` builds its insert from the columns the database has, so a migration that has not run cannot stop the inbox. Prompt corpus unchanged. Code in this commit; record in [24](24-location-pins.md). | Bhavin | Re-upload the 5.18.17 ZIP, minutes; migration 072 only adds columns and is not reversed by a downgrade | Pending upload. After upload, send a location pin to the sales number from a phone not in the CRM: the reply must acknowledge it, and `grep 'location pin' ` the AI log should show `in area` |
| 2026-09-18 | Plugin 5.18.20 built for upload via uCRM → Settings → Plugins. **Phase 2 of 3 — the AI lead reaches uCRM.** Qualified leads have been written to `leads.json` since 5.18.3 and nothing ever carried them into uCRM. New `lib/UcrmLeadSync.php` creates them as lead clients (`isLead: true`) through the existing `CrmApiClient` and the payload `cron/kyc_crm_sync.php` already uses, driven by a new `crm.lead.sync` event and `workers/UcrmLeadWorker.php` so a customer never waits on uCRM for their reply. Idempotent four ways: an existing `crm_client_id` patches, a known phone links, two clients on one number REFUSE and flag for a person, and a create happens once under a lock that re-reads the lead. `organizationId`/`countryId` are read off the clients this install has (overridable with `ucrm_lead_organization_id` / `ucrm_lead_country_id`), never copied from the KYC literals. Coordinates go on as `gpsLat`/`gpsLon` and the patch rewrites nothing else. A uCRM outage is distinguished from an empty result, so an unreachable CRM is retried rather than read as "nobody has this number". **Ships OFF** — set `ai_crm_lead_sync` to enable. Prompt corpus unchanged. Code in this commit; record in [25](25-ai-lead-into-ucrm.md). | Bhavin | Re-upload the 5.18.18 ZIP, or set `ai_crm_lead_sync --clear` which stops all uCRM writes immediately | Pending upload. `tools/org_probe.php` was run on 18 Sep before release: **one organization, id 1 DishNet Africa Limited, all 47 of 47 sampled clients on it**, so the run-time derivation returns 1 and no config is needed — and the KYC literal `organizationId => 2` is wrong for this install. The probe also showed the country lives on the organization (247) rather than on clients, so 5.18.20 consults the organization when the clients are silent, and refuses where two organizations and no clients make it a guess |
| 2026-09-18 | Plugin 5.18.21 built for upload via uCRM → Settings → Plugins. **The operator's business facts were never in the prompt on this install.** `DishNetAiBrain::coverageRules()` was an if/else and `localFacts()` had its only call site inside the `else`; with 34 knowledge entries seeded (confirmed by a read-only query on the live box) that branch never runs, so `ai_fact_payment` (the Ecobank account), `ai_fact_office`, `ai_fact_delivery`, `ai_fact_prices` and `ai_fact_location_pin` all reached nothing — and 5.18.14/15/16 were written against a dead path. The payment refusal came from the knowledge rule `RULE_PAYMENT_SAFETY` ("details on the invoice"), not from the `ai_fact_payment` default; doc 22 is corrected. `businessFactsBlock()` is now called from both branches, the knowledge-base branch taking operator-SET facts only so South Sudan defaults cannot leak into a Uganda prompt. Legacy path byte-identical. **Prompt corpus deliberately changed** `ba05b3dd` → `f770081e`. Code in this commit; record in [26](26-operator-facts-vs-knowledge-base.md). | Bhavin | Re-upload the 5.18.20 ZIP, minutes; no data or config is touched by this change | Pending upload. **This alone will not make the assistant give out the bank account** — `RULE_PAYMENT_SAFETY` still tells it to use the invoice. That reword is proposed separately and awaits approval |

## 5.18.22 — the customer who picks Business 50 themselves

**18 September 2026** · `lib/DishNetAiBrain.php` (`qualification()`),
`tests/test_plan_recommendation.php` (new), `tests/test_ai_qualification.php`

A real conversation on 18 September: home, ten people, asked the price. The
assistant recommended Residential and did it well — that path was never broken.
The broken path is the other one. Every rule in `qualification()` governs what
the assistant RECOMMENDS; none of them covered a customer who arrives having
already chosen. "How much is Business 50?" matched nothing, so it was simply
quoted — and Business 50 is the cheapest line on the list, the number reads as a
speed, and a 50 GB priority block is gone in days on a busy household.

Changed:

- **Self-selected Business plans.** When the customer names one, asks its price
  or says it looks cheaper, the consequence is stated BEFORE any price: the tier
  numbers are priority data, not speeds; after the block the line drops to about
  1 Mbps until more data is bought. Then the higher-capacity Residential plan is
  recommended. If they still want Business, it is quoted without argument — they
  have been told, and it is their money.
- **The 1 Mbps figure**, confirmed by the operator. `BUSINESS_PLANS` in the
  knowledge base says "behaves like standard data", which is true and persuades
  nobody. *(The knowledge-base entry itself is unchanged and awaits approval.)*
- **The higher-capacity Residential plan is now the default answer**, not merely
  preferred — operator decision.
- **The Mini is the kit led with**, as the lower upfront total. The rule that no
  plan requires a particular kit survives unchanged.
- **The Business steer is reversed**: where a remote-access requirement is real,
  the assistant still leads with Residential. It must never claim remote access
  works on it — the public IP is named as a separate quotation and the
  conversation is handed over. Selling the plan is a commercial choice; claiming
  a capability CGNAT does not provide is the assistant inventing network
  availability.

Tests: 159 files green, 0 failures. `test_ai_qualification.php` lost two
assertions pinning the old Business steer; they were replaced, not deleted, so
the protection moved rather than disappearing.

## 5.18.23 — the follow-up evaluator asks which provider this install uses

**18 September 2026** · `lib/FollowUpEvaluator.php`, `cron/followup_run.php`,
`tools/set_config.php`, `tests/test_followup_provider.php` (new)

`cron/followup_run.php` read `claude_api_key`, and only that, then built a
`ClaudeWaClient` with it. The Uganda install runs `ai_provider=openai`, so the
key was empty, the script returned at its guard on every run, and the only
trace was one `error_log` line. Confirmed four independent ways: 2,300 log
occurrences over ~5.3 days matching the queue's age, `DONE followup_run in
14ms` where a real evaluation takes seconds, the explicit message, and
`sales_stage = 'unknown'` on all 264 open rows — a column written only after
the evaluator returns.

`followup_scan` needs no key (SQL only), so it kept opening rows. 261
follow-ups accumulated and not one draft was ever written.

Changed:

- **`FollowUpEvaluator::clientFor()`** — provider selection as a function a test
  can call, because the four lines it replaces lived in a cron script with no
  seam, which is why nothing caught them for five days. Every other
  provider-consuming site in the plugin already branched on `ai_provider`;
  this was the only one that did not.
- **The error names the provider.** `no API key` told nobody which of two keys
  to set.
- **`followup_run_limit` registered in `set_config.php`.** It was read at
  `followup_run.php:47` but never registered, so the per-run evaluation limit
  could not be turned down. At the default 5, a 257-row backlog is seven hours
  of drafting.

Not changed, deliberately: sending. `followup_send.php` still sends only drafts
a person approved, and `followup_run.php` still has no path to Evolution. A test
asserts both, so fixing evaluation cannot quietly turn the engine into an
autoresponder.

The two clients are not interchangeable in general — `ClaudeWaClient::getReply()`
takes a seventh `$tools` argument `GptWaClient` does not have. They are
interchangeable here because `evaluate()` passes six, and a test pins that.

Tests: 160 files green, 0 failures. A mutation reinstating the original bug
fails 8 assertions.

## 5.18.24 — WhatsApp enquiry follow-ups may send themselves

**19 September 2026** · `lib/FollowUpPolicy.php`, `cron/followup_run.php`,
`tools/set_config.php`, `tests/test_followup_auto_send.php` (new)

Operator decision: WhatsApp is one-to-one, the message is short, and it goes
to somebody who wrote to us first about something they asked. Email keeps its
own policy and is unchanged.

`FollowUpPolicy::mayAutoSend()` — four conditions, all required:

1. `followup_auto_send` is on. **Absent means off**, so an install that
   upgrades into this behaves exactly as it did the day before.
2. The assistant returned `SEND`. `WAIT`, `DO_NOT_SEND` and
   `ESCALATE_TO_HUMAN` never auto-send.
3. There is a message. An empty body is a bug, not a send.
4. The content level is `CONTENT_ENQUIRY`. **`CONTENT_ACCOUNT` still waits for
   a person** — provenance good enough to ANSWER a balance question is not
   provenance good enough to SEND somebody an unread message about their
   money. A wrong identity wastes a message in the first case and puts another
   customer's balance on a stranger's phone in the second.

Plus: the drafted BODY is scanned for escalation words. The customer's own
words already close the follow-up at gate 6, so no draft can exist for those;
this is the other direction — the assistant writing about a refund.

**Auto-send approves a draft. It does not send one.** `followup_send.php`
still delivers, and still re-checks opt-out, human takeover, a reply arriving
and the sending window between approval and delivery. Routing through
`approve()` rather than around it is what keeps those four protections on the
automatic path, and leaves `decided_by = 'auto'` in the trail. A test asserts
`followup_run.php` still has no way to reach Evolution at all.

Email untouched and asserted: `EmailReplyPolicy::NEVER_AUTO` still holds back
13 categories unconditionally, and `followup_auto_send` does not unlock any of
them.

Tests: 161 files green, 0 failures. Mutations removing the account gate or the
master switch each fail 3 assertions.

**Not enabled.** `followup_auto_send` is unset. Do not switch it on until at
least ten drafts have been read — no draft has ever been produced on this
install, so there is no evidence yet about their quality, and 257 open
follow-ups against a cap of 30/day is nine days of unread messages.

## 5.18.28 — KYC customers reach uCRM

**25 September 2026** · `lib/UcrmClientTarget.php` (new), `lib/KycCrmSync.php`
(new), `lib/EfrisClientField.php` (new), `lib/KycService.php`,
`lib/EfrisInvoiceMapper.php`, `cron/kyc_crm_sync.php`,
`includes/post/post_kyc.php`, `tabs/sales/applications.php`, `main.php`,
`includes/api/api_crm_misc.php`, `tests/test_kyc_crm_create.php` (new),
`tests/fixtures/fake_ucrm_kyc.php` (new). Full record:
[30](30-kyc-customers-not-reaching-ucrm.md).

Every customer registered through the staff app's KYC wizard was saved in the
plugin and refused by uCRM with `404 Not Found`: the create named organization
2 and custom fields 36–43, none of which exist on this uCRM, and custom field 1
— which it sent as *Sales Person* — is the EFRIS TIN here. The agent was told
"Customer saved!", Orders counted the customer "In CRM ✓", and the retry job
had never run. Three applications (12–24 September) were waiting.

The organization and custom fields are now checked against the connected uCRM
before the create: organization 2 where it exists, else the only one, else
refuse; the nine fields only where all nine exist and none is a tax field.
This install gets organization 1 and no custom fields; the South Sudan layout
gets a byte-identical request. The retry job works (claim, fit, a same-phone
check that stops for a person, create, then what the form would have done),
a refused create is shown in amber with uCRM's reason, Orders counts from the
uCRM id, and an admin can retry from Orders.

Tests: 112 assertions in the new file; 29 weakened copies each caught by
counted failures; full suite 186 files green twice.

**Applied by:** the operator, with `deploy-hybrid.sh`.
**Rollback:** `git checkout <the commit --check reported>` and deploy again;
clients already created in uCRM stay.
**Status:** deployed 25 September 2026 (`0d20e05`, over 5.18.27 `90cf102`).
The retry's first run created applications 1 and 3 as leads and stopped
application 2 for a check: uCRM client #10 has the same phone number. Record:
[30](30-kyc-customers-not-reaching-ucrm.md) §7.

## 5.18.29 — the steps after the create, checked before they run

**25 September 2026** · `lib/KycService.php`, `lib/KycCrmSync.php`,
`lib/NotificationService.php`, `cron/kyc_crm_sync.php`,
`includes/post/post_kyc.php`, `tabs/sales/applications.php`,
`tools/set_config.php`, `tests/test_kyc_crm_create.php`,
`tests/fixtures/fake_ucrm_kyc.php`. Record:
[30](30-kyc-customers-not-reaching-ucrm.md) §8.

No KYC customer had reached uCRM on this install before 5.18.28, so the form's
steps after a create had never run here. Before the next registration runs
them:

- the retry quotes what the form quotes: one shared builder and sender, the
  lines the form built are kept on a refused application, and the same switch,
  limit and credential apply;
- the booking confirmation's installation times come from
  `kyc_welcome_timeline` (unset keeps the South Sudan Fiber, Starlink and
  DishNet 4G lines; `omit` drops them);
- Orders offers "This is uCRM client #N" for an application the phone check
  stopped (admins only; it creates nothing in uCRM);
- the retry stops starting creates after 40 seconds a run.

**Applied by:** the operator: `deploy-hybrid.sh`, then one `set_config.php`
line for `kyc_welcome_timeline`.
**Rollback:** `git checkout 0d20e05` and deploy again; clear the setting with
`--clear`.
**Status:** deployed 25 September 2026 (`ea18fef`, over `0d20e05`).
`kyc_welcome_timeline` was set to the FAQ's wording, then to `omit` the same
day, so the confirmation promises no installation time, as `ai_fact_delivery`
already tells the assistant. Application 2 still waits for a person to compare
it with uCRM client #10.

## 5.18.30 — a KYC customer gets uCRM's WhatsApp messages

**25 September 2026** · `webhook.php`, `lib/NotificationService.php`,
`lib/QuoteWaLedger.php` (new), `cron_quote_wa.php`, `tools/set_config.php`,
`tests/test_kyc_crm_messages.php` (new), `tests/fixtures/fake_ucrm_kyc.php`.
Record: [30](30-kyc-customers-not-reaching-ucrm.md) §9.

At the operator's request, a customer registered with the KYC form can now get
the WhatsApp a customer created in uCRM gets: "🎉 Welcome to DishNet!", then
the "📄 Quotation & Order Summary" with the quotation PDF. Until now they got
the plugin's "Request Confirmed!" and, three minutes or more later, a
proforma-style quotation. A customer the retry job created got no greeting at
all.

- New setting `kyc_messages_like_crm` (yes/no). **Unset changes nothing** —
  South Sudan, and this install until it is set.
- On, uCRM's `client.add` welcomes KYC customers too, and the form's own
  customer message is not sent; the agent's message stays.
- On, uCRM's `quote.add` sends the summary and the PDF straight away, the same
  path as a quote made in uCRM.
- `cron_quote_wa.php` stays as the fallback if uCRM's quote webhook never
  arrives. The webhook and the cron claim each quote in the table the cron
  already used against double sends (now `lib/QuoteWaLedger.php`), so only
  one of them sends it. If the claim cannot be written, nothing is sent.
- Fixed on the way: the dry-run message log could erase itself when a message
  was cut through an emoji. It now cuts on a character boundary. Dry-run
  only.

Tests: 58 assertions in the new file, driving the real form, the real webhook
under `php -S` and the real cron against a fake uCRM; 16 weakened copies each
caught by counted failures; full suite 187 files green twice.

**Applied by:** the operator: `deploy-hybrid.sh`, then one `set_config.php`
line setting `kyc_messages_like_crm` to 1.
**Rollback:** clear the setting with `--clear` (the old messages return at
once), or `git checkout 96c0f91` and deploy again.
**Status:** deployed 25 September 2026 (`723233a`, over `ea18fef`).
`kyc_messages_like_crm` was set to 1 straight after, and the settings file
reads it back ON. The KYC form reads the store's copy instead; a read-only
check of that copy showed `'1'` there too ([30](30-kyc-customers-not-reaching-ucrm.md)
§9). Live evidence of the messages waits for the next KYC registration.

## 5.18.31 + website — Airtel Money, merchant ID 4428146

**25 September 2026** · `lib/PaymentOptions.php` (new), `webhook.php`,
`lib/CustomerEmails.php`, `lib/DishNetAiBrain.php`, `tools/ai_facts.php`,
`tools/set_config.php`, `tests/test_airtel_money.php` (new);
`dishnet-web-uganda/site/pay.html`, `faq.html`. Record:
[31](31-airtel-money-merchant.md).

DishNet's Airtel Money Pay merchant ID becomes a payment option everywhere
the system tells a customer how to pay. A customer dials `*185*9#`, enters
4428146, the amount and their PIN — free of charge to them — then sends the
transaction ID so billing can record it.

- **Website:** `pay.html` leads with an Airtel Money section (steps, the app
  QR, what to send afterwards), and no longer promises automatic reflection.
  The FAQ answer and its structured data match.
- **Plugin:** the setting `pay_airtel_merchant` puts Airtel Money on the
  WhatsApp quotation summary and in the e-mails' "How to pay". **Unset
  changes nothing.**
- **The assistant:** a new `ai_fact_payment` text, Airtel Money plus the
  unchanged Ecobank transfer. When that text carries a USSD code, the prompt
  tells the assistant to keep the code off lines with other asterisks,
  because WhatsApp bold can eat one of them.

Tests: 58 assertions, including the quotation through the real webhook to a
fake Evolution API that keeps whole messages; 13 weakened copies each caught
by counted failures; full suite 188 files green twice. Both site verifiers
pass.

**Applied by:** the operator: `deploy-hybrid.sh`, two `set_config.php`
lines, and a website redeploy in EasyPanel.
**Rollback:** `--clear` on either setting, `git checkout d52b30a` for the
code, the previous build for the website.
**Status:** plugin deployed 25 September 2026 (`f3ab37e`, over `723233a`);
the container serves `f3ab37e`. Both settings were set straight after, and
the settings listing reads each back: `pay_airtel_merchant` "4428146", and
`ai_fact_payment` starting *"Pay by Airtel Money or by bank transfer."*
The website redeploy has not been reported yet; the entry below goes out
with it.

## Website — no MTN Mobile Money, and the app is coming soon

**25 September 2026** · `dishnet-web-uganda/site/pay.html`, `faq.html`,
`get-the-app.html`, `about.html`, `why-dishnet.html`, `services.html`,
`hotspot.html`, `blog-wifi-hotspot-business-uganda.html`;
`dishnet-web-uganda/README-DEPLOY.md`. Record:
[31](31-airtel-money-merchant.md) §7.

The operator answered the two questions [31](31-airtel-money-merchant.md)
§5 had left open: *"we dont have momo for now"* and *"app is not yet
published"*. The website said the opposite of both.

- **MTN Mobile Money is no longer offered.** The only mention left is
  *"We do not take MTN Mobile Money at the moment"*, on the pay page and in
  the FAQ answer (its visible text and its structured data).
- **Paying in the app or the portal is no longer offered.** The pay page's
  "In the app" card is gone. The portal card now says the portal shows
  invoices and payment history, and to pay with Airtel Money. The FAQ's
  balance-and-bill answer says the same.
- **The app page says *coming soon*.** Its download button pointed at a file
  that was never on the site; it is now a WhatsApp "Tell me when it is
  ready" button. The page's description no longer says the app pays bills.
- Five other pages that said "MTN MoMo and Airtel Money" now say Airtel Money:
  `about`, `why-dishnet`, `services`, `hotspot` and the WiFi-zone blog post.

Both site verifiers pass. Checked in a browser at desktop and phone width,
with no horizontal scroll.

**Applied by:** the operator: the website redeploy in EasyPanel (project
`web`, app `web-uganda`), which also publishes 5.18.31's pay page.
**Rollback:** the previous build.
**Status:** not deployed.

## 5.18.32 — DPO Pay checked against DPO's instructions; a test link for their review

**25 September 2026** · `lib/DpoClient.php`, `lib/DpoPaymentService.php`,
`lib/DpoBootstrap.php`, `lib/ConfigVault.php`, `dpo_test.php` (new),
`dpo_return.php`, `public.php`, `tabs/admin/dpo_payments.php`,
`tabs/customer_app/portal_data.php`, `tools/dpo_probe.php` (new),
`tests/test_dpo_review_link.php` (new), the two DPO fakes and two DPO suites.
Record: [32](32-dpo-pay-review.md); technical: the DPO build spec §9.

DPO sent test credentials and asked for a test link their team can pay through
before they issue live credentials. Checked against their instructions:

- **DPO's endpoint and page.** verifyToken now posts to `/API/v6/`, not `/API/v7/`,
  and customers go to `payv3.php`, not `payv2.php`. Both older values came from
  DPO's published code.
- **The test environment was open to every customer**, and a payment with DPO's
  public test cards would have been posted to uCRM against a real invoice. Now
  only the test customers named on the admin screen can pay while the
  environment is test. A test payment for anyone else is quarantined, never
  posted.
- **A test link:** `public.php?page=dpo_test&k=<key>`. It lists the test
  customers' unpaid invoices with a Pay button and needs no sign-in. It opens
  in the test environment only, with a key the admin screen makes and can
  replace.
- **Only unpaid and part-paid invoices** (uCRM 1 and 2) can be paid online. The
  code had read 4 as paid; in uCRM 3 is paid and 4 is void. Nothing was ever
  wrongly payable.
- **`tools/dpo_probe.php`** asks DPO whether the saved token and currency are
  accepted, before anyone is sent the link. It runs in test only and never
  prints the token.

Tests: 85 checks in the new file, including the test link through `php -S` from
Pay to *Payment successful*; 16 weakened copies each caught by counted
failures; full suite green twice.

**Applied by:** the operator: `deploy-hybrid.sh`, then the steps in
[32](32-dpo-pay-review.md) §4 — test client in uCRM, DPO Pay settings, the probe,
the link to DPO.
**Rollback:** set DPO Pay to *Disabled* on the admin screen, or
`git checkout a987f08` (5.18.31) and deploy again.
**Status:** deployed 25 September 2026 (`1fd1478`, over `f3ab37e`); the
container serves `1fd1478`. The probe then answered *No company token is set*,
as it should before any is entered.

## 5.18.33 — the DPO probe checks what was typed before asking DPO

**25 September 2026** · `tools/dpo_probe.php`, `tests/test_dpo_review_link.php`,
`tests/fixtures/fake_dpo_server.php`. Record: [32](32-dpo-pay-review.md) §8.

On the server, `dpo_probe.php --ask` was not given a DPO token. The text at the
service-type prompt was not a service type, most likely the clipboard's
contents. The tool sent whatever was at the token prompt to DPO, and DPO
answered 801, *Request missing company token*.

- The tool now checks **before anything goes to DPO**. A company token must look
  like one of DPO's: 36 characters, 8-4-4-4-12. A service type must be a number.
  Anything else is refused with what a token looks like. It is not sent and not
  printed back.
- The same check applies to a **saved** token.
- A paste's bracketed-paste markers and invisible characters (non-breaking and
  zero-width spaces, a byte-order mark) are removed first, so a real token
  copied from an e-mail still works.

Tests: 93 checks in `test_dpo_review_link.php` (was 85), including the wrong
clipboard at both prompts; 4 weakened copies each caught. The tool, its test and
the fake DPO are all this touches: the seven tests that use them passed twice.

**Applied by:** the operator: `deploy-hybrid.sh`. Optional — the probe already
works when only the DPO token is pasted.
**Rollback:** `git checkout 1fd1478` and deploy again.
**Status:** deployed 25 September 2026 with 5.18.36 (`c82e0b9`).

## 5.18.34 + website — links without `:8443`

**25 September 2026** · Plugin: `lib/crm_url.php`, `lib/PluginConfig.php`,
`lib/ConfigVault.php`, `tools/crm_url_check.php`, twelve files that built an
address themselves, `tests/test_links_without_port.php`, `tests/run.sh`,
`tests/test_tools_smoke.php`. Website: 57 pages, `verify-site.sh`,
`README-DEPLOY.md`. Record: [33](33-links-without-port.md).

The operator asked why links carry `:8443`, and said the customer login does not
work. Three sources, measured in the code:

- **The website's Customer Login** linked the bare `https://crm.dishnetuganda.com/crm`
  on every page. UISP's web server redirects that to `/crm/` and adds its own
  port, which is the address in the question. All 176 links now open
  `/crm/login`. `verify-site.sh` fails on any bare `/crm`, shown by planting one.
- **The plugin's own setting reached only half the plugin.** `crm_public_url`
  (set in September, [09](09-mail-and-pdf-delivery.md)) is in the data
  directory's `config.json`, and only `PluginConfig::load()` merges that file.
  The admin screens, the customer portal, the API and about a hundred other
  readers hold the settings store's copy, so their links kept `:8443`. That
  includes the DPO return, push and test addresses shown to be sent to DPO.
  - `dn_public_override()` now reads the install's value, read-only, when the
    caller's copy lacks it. A caller with no config at all still gets none: the
    two such callers talk to a sibling plugin.
  - `crm_public_url` is now a vault key.
  - `--set` and `--clear` write both the file and the vault, and remove a second
    copy anywhere else.
- **Twelve files built addresses themselves**, from uCRM's raw address or the
  request's host. Each now goes through `dn_with_override()`, which changes
  nothing where the setting is absent. A scan in the new test fails on any new
  one; every exception is named with its reason.

**Not the plugin's:** uCRM's own e-mails and redirects (the client zone
invitation, uCRM's invoice e-mails). Routers stay on `:8443`
([05](05-domain-and-tls-plan.md)). **Found and left:** the overdue ladder links
customers to uCRM's staff page, and the workbench's pay link looks in the wrong
folder. The ladder is off on Uganda (prepaid), so these reach Sudan only.

Tests: `test_links_without_port.php` 47 checks; 11 weakened copies each caught.
Plugin suite: **190 files, 8,454 checks, 0 failed, twice.** The runner now gives
each test its own vault: a vault shared by the run carried the new key from one
test into four others. Its guard was rewritten to say so, and fails on the old
runner. Sudan: no `crm_public_url`, so its links keep their host and port. The
one change there is the customer lookup's uCRM links, which doubled `/crm` in the
path and now do not.

**Applied by:** the operator: `deploy-hybrid.sh`, then `tools/crm_url_check.php`
(and `--set https://crm.dishnetuganda.com` if it shows none). Then, after
opening `/crm/login` once, the website redeploy in EasyPanel.
**Rollback:** `git checkout b4cc109` and deploy again; the website's previous build.
**Status:** plugin deployed 25 September 2026 with 5.18.36 (`c82e0b9`). The
`crm_url_check.php` result is not reported yet. The website is not redeployed.

## 5.18.35 — the DPO probe shows nothing typed at either prompt

**25 September 2026** · `tools/dpo_probe.php`, `tests/test_dpo_review_link.php`.
Record: [32](32-dpo-pay-review.md) §8.

The operator ran `dpo_probe.php --ask` a second time, with 5.18.33 not yet
deployed, so the old tool ran. The token prompt reached DPO empty, and DPO
answered 801. What went in at the service-type prompt was again not a number.
That prompt echoed it, so it went up on the screen and from there into a copy of
the terminal. It is not recorded anywhere.

- **Neither prompt echoes now.** Echo is turned off before the label is
  printed, so nothing typed the moment it appears is shown either.
- **The tool says what arrived without showing it:**
  `Company token: received, 36 characters (not shown)`, and the service type
  shown back once it is known to be a number.
- **Each answer is checked as soon as it is given.** A wrong paste at the first
  prompt is not followed by a second one.
  - Nothing typed: *Nothing arrived at the company token prompt*.
  - Anything else: described by its length only.
  - Either way, nothing is sent to DPO.

Tests: `test_dpo_review_link.php` 103 checks (was 93).

- The probe now also runs on a real pseudo-terminal, as `docker exec -it` does,
  answering each prompt once it is on the screen. A pipe never echoes, so only
  this can see what reaches the screen.
- Six weakened copies are each caught, the 5.18.34 prompt among them.
- The first run of the weakened copies hung the test instead of failing it: a
  probe left waiting at an unanswered prompt. The test now stops it after 15
  seconds and counts that as a failure.
- Plugin suite: **190 files, 8,464 checks, 0 failed, twice**, with the terminal
  checks run each time, not skipped.

**Applied by:** the operator: `deploy-hybrid.sh`. It deploys 5.18.33, 5.18.34
and this together.
**Rollback:** `git checkout bd0b329` and deploy again.
**Status:** deployed 25 September 2026 with 5.18.36 (`c82e0b9`).

## 5.18.36 — every DPO door reads what the DPO Pay screen saved

**25 September 2026** · `lib/DpoBootstrap.php`, `lib/ConfigVault.php`,
`tabs/admin/dpo_payments.php`, `tools/dpo_probe.php`,
`tests/test_dpo_one_source.php`. Record: [32](32-dpo-pay-review.md) §8.

After deploying 5.18.35, the operator saved the test token on the DPO Pay
screen, and `dpo_probe.php` still answered *No company token is set*. **No save
made on that screen had ever reached the vault**, since the first build:

- `ConfigVault::store()` refuses a whole batch for one key it does not keep.
  The screen's batch carried two such keys: `dpo_currencies` and
  `dpo_unpayable_statuses`.
- The screen reported the failure in green.
- The screen, the portal and the payment API read the settings store. The
  reviewer's test page, the return page, DPO's push, the reconcile job and the
  probe read only files and the vault, so they never saw the token.
- A payment could therefore be started and never verified. Whether any was
  started shows in the DPO Pay screen's list of transactions.

What changed:

- **`DpoBootstrap::vaulted()`**, which every DPO door goes through, now puts
  what the screen saved first, a saved blank included. Only keys the screen
  never saved come from the caller or the vault.
- The screen backs up the **settings in effect**, not only the fields posted,
  and hands the vault only keys it keeps. A failure shows in red.
- The two keys are now vault keys.
- The probe says where its settings came from. When there is no token, it
  says whether the screen has one saved.

Tests:

- `test_dpo_one_source.php`, 28 checks. It presses **Save settings** on the real
  screen, then rebuilds the server's state: token saved on the screen, a stale
  one in the vault.
- The probe then reaches a fake DPO with the screen's token: `000`, where the
  stale token would have been answered `802`.
- The screen's own test had only found `ConfigVault::store` in the source.
- 7 weakened copies are each caught.
- Plugin suite: **191 files, 8,492 checks, 0 failed, twice**.

**Applied by:** the operator: `deploy-hybrid.sh`. Then **Save settings** once on
the DPO Pay screen (the token field can stay blank), which backs up the
settings in effect.
**Rollback:** `git checkout e7b754f` and deploy again.
**Status:** deployed 25 September 2026 (`c82e0b9`, "✓ container now serves
c82e0b9"). On the server afterwards:
- The probe read the saved settings. Its vault line listed every DPO setting,
  which only a Save made on the screen can put there, so the Save now reaches
  the vault.
- It refused the saved company token because it is not shaped like one of
  DPO's, before sending anything.

## 5.18.37 — the customer-login and payment doors answer only whom they should (audit Phase 1)

**25 September 2026** · `includes/api_handlers.php`, `includes/api/api_public.php`,
`includes/api/api_public_files.php` (new), `includes/api/api_staff_diagnostics.php` (new),
`includes/api/api_customer_support.php` (new), `includes/api/api_cron_debug.php`,
`includes/api/api_payments_admin.php`, `includes/api/api_products_admin.php`,
`includes/api/api_customer_app.php`, `includes/routes.php`, `webhook.php`,
`lib/PdfLinkToken.php` (new), `lib/NotificationService.php`, `lib/ConfigVault.php`,
`lib/PluginConfig.php`, `tabs/customer_app/login_web.php`, the PDF-link minting
sites, `tools/crm_debug.php` (new), `tools/crm_webhook_key.php` (new), six test
suites. Record: the private Phase 1 report handed to the operator (the audit
that produced it lists live weaknesses and is deliberately not in the
repository).

Phase 1 of the customer-login / portal / payments audit, scope §C of the
approved remediation plan. **Code and configuration only — no migration.**
Nothing here changes a stored row, a table, a default or a trigger; the
previous release opens the same data directory unchanged.

- **What the API answers without a login is now a list, and a test pins it**
  (`tests/test_preauth_allowlist.php`, 47 actions: staff login, the key-gated
  customer-context read, four PDF links with their own tokens, the customer
  app's own actions behind the customer's token, and the two DPO actions).
  Three staff API files that had been included before the sign-in check —
  payments admin, products admin, cron/debug/backup — and seven diagnostics
  in the public file now run behind it, administrator-only where they change
  money or read the whole installation. The two actions that had hidden
  behind a constant key written in the source no longer read a key at all.
  `?page=crm_debug` is gone; `php tools/crm_debug.php --status` on the server
  replaces it. `test_payment_post` needs `&confirm=<collection_id>` and posts
  through the de-duplicating path, so a repeated URL cannot post twice.
- **The customer sign-in door no longer says who is a customer.** A known and
  an unknown phone or e-mail get the same answer; the difference is written to
  the audit rows for staff. A per-address limit throttles an enumeration run
  like a real customer (counted in the existing `app_otp_rate` table under
  `ip:<address>` rows; no new table). The four `app_debug_*` actions —
  account lookup with another customer's row as a sample, every message ever
  sent to a number with its first line (the login code), every plugin's
  tables, a WhatsApp send to any registered number — are removed. Staff get
  `staff_login_lookup` and `staff_otp_log` (administrator only; no message
  text, no code, nobody else's account). `app_debug_list` is administrator
  only. The notification log's preview for a login code is a fixed notice.
- **Consent is recorded for the identity the code proved** — the token the
  sign-in issued — never for an identifier typed into the request. The login
  page sends that token with the consent call.
- **The uCRM webhook acts on uCRM's copy of every entity, never the posted
  body.** Each handler re-reads its entity by id; an id uCRM does not know,
  or a uCRM that cannot be reached, is skipped with a 200 and logged as
  `entity_unverified`. `payment.delete` reverses only a payment uCRM answers
  404 for. `client.message`, whose text cannot be re-read, is forwarded only
  when the request carries the new optional key `crm_webhook_key`; once that
  key is set (`php tools/crm_webhook_key.php --generate`, then the same value
  in uCRM's webhook settings) every request without it is refused.
- **Receipt and delivery-note links carry a key of their own**
  (`pdf_link_secret`, generated once at boot, vaulted like the quotation key)
  instead of `webhook_secret ?? 'dishnet'`. The old scheme is accepted for its
  last day only where `webhook_secret` was a real value — never under the
  `'dishnet'` default. Temporary PDFs get an unguessable token.

Tests: five new suites (`test_preauth_allowlist` 105, `test_customer_login_security`
65, `test_webhook_trust` 74, `test_pdf_link_token` 69, `test_otp_log_privacy` 16),
three existing suites and one fixture updated to the moved code, twelve
weakened copies of the controls each caught by the suite that guards it.
Plugin suite: **196 files, 8,822 checks, 0 failed, twice**.

Visible after deployment, to decide with eyes open:
- A visitor who types a number that is not a customer's now sees "Code sent"
  and the page's help copy, not "no account". Support answers with
  `staff_login_lookup`.
- During a uCRM outage the webhook skips events (uCRM does not retry a 200);
  the nightly sync and the payment catch-up job still reconcile payments.
- On an install whose `webhook_secret` is empty — Uganda's was recorded empty —
  receipt and delivery links sent before the deployment stop opening at the
  deployment; links minted afterwards work for 24–48 hours as before.
- `backup_download` and `cron_trigger` need an administrator's session or
  token; nothing on the server is known to call them over HTTP.

Recorded, not changed (outside §C): the e-mail sign-in identifier cannot match
on the structured customer index of migration 054 (no e-mail column), so e-mail
sign-in is inert on this schema; the cashbook auto-post throws on an integer
`method` from uCRM under strict types and logs `cashbook_error` (pre-existing);
the two EFRIS PDF links still use the old signing scheme; the `+211` country
prefix and the customer-token secret derivation are Phase 2.

**Applied by:** the operator, after explicit approval (given 25 September
2026, with one change to the sequence: `crm_webhook_key` is NOT configured in
the deployment window — first prove, read-only, whether this uCRM can send a
per-endpoint secret at all). One command, which records before-evidence, backs
up the plugin's data directory, runs `deploy-hybrid.sh`, then the safe smoke
tests, a read-only webhook inspection and the receipt-link check:

```bash
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
  && mkdir -p /root/dnb-phase1 \
  && bash scripts/phase1-deploy.sh 2>&1 | tee /root/dnb-phase1/deploy-$(date -u +%Y%m%dT%H%M%SZ).log
```

`scripts/phase1-deploy.sh` never configures the webhook key, never contacts a
customer, never posts a payment and prints no token, code or secret; the
deployment report is written from its log file. `php tools/crm_debug.php
--status` replaces the removed debug page.
**Rollback:** `git checkout 9b75085` and deploy again. No data step: the new
build writes only `pdf_link_secret` into the settings store and `ip:` rows into
`app_otp_rate`, both ignored by 5.18.36.
**Status:** **deployed 25 September 2026 20:07 UTC** (`68f4eeb`, "✓ container
now serves 68f4eeb", over live `c82e0b9`) by the one command above, first
attempt. Smoke tests **35 ok, 0 failed, 2 notes**: the customer sign-in page
answers; all 27 moved actions and the 4 removed ones are 401 anonymously; the
constant key opens nothing; an administrator reaches the moved diagnostics and
a non-administrator gets 403; the sign-in door answers an unknown identifier
uniformly; the CRM debug CLI answers; no PHP fatal; `pdf_link_secret` was
generated and vaulted at the first page load; a receipt link signed the old way
is **403** and one minted with `PdfLinkToken` is **200** (the documented
consequence for pre-deployment links, confirmed). `crm_webhook_key` was **not**
configured, by decision. Two defects of the deployment script itself, found by
the run and fixed afterwards (`b24e2f7` → the next commit): the backup archives
were both named `.tar.gz`, so the 116 KB archive of `data/` overwrote the 91 MB
archive of the real data directory (the deploy never touches data, so the
rollback path is unaffected; a fresh backup is to be taken with the corrected
script or by hand); and the read-only webhook inspection called
`webhook/endpoints` instead of `webhooks/endpoints`, so the first inspection was
not evidence. **Re-run 20:15 UTC with the plugin's own client:** base
`http://localhost/crm/api/v2.1`, the control `GET payment-methods` answered
(23 methods), and `GET webhooks/endpoints` answered **404 Not Found** on API v2.1 **and on
v1.0** (20:17 UTC) — this uCRM's API does not serve the webhook-endpoint objects, so they cannot be
read (nor a secret field seen) over the API; the uCRM UI (System → Webhooks) is
the only view, and the operator's reading of that form decides whether
`crm_webhook_key` can ever be configured here. *(Resolved 26 Sep 2026: the form was read and has
no such field — see the 5.18.38 record. "uCRM cannot supply the Phase-1 optional webhook key through the available interface.")* **Pre-existing finding recorded
by this run:** the plugin's own `CrmApiClient::getWebhooks()` — behind the
Settings tab's webhook tile, the *Setup Webhook* button and `WebhookRegistrar`
— asks that same v2.1 route and therefore sees no endpoint on this uCRM;
delivery of events is unaffected (300 log entries) because the endpoint was
configured in the UI. Not Phase 1's to change. Consequence to decide: while no
key mechanism exists, `client.message` (uCRM "message to client" → WhatsApp
forward) is ignored by 5.18.37. The checks ran against the `:8443` address with certificate
verification off because the public address was read from the wrong file;
also fixed. Code delivery to a phone was not exercised live (no `--login`).

**Closure run, 25 Sep 2026 20:38 UTC (`80f66fa`, `--login-only --login` with the
operator's own registered number):** the data-directory backup the deploy run
lost was re-taken by hand at 20:12 UTC and verified read-only — 95,171,116
bytes, mode 600, gzip integrity ok, 701 archive entries against 701 entries in
the live directory, one top-level directory; the corrected backup naming was
exercised against the original collision (distinct names, a repeat gets a time
suffix). The controlled sign-in passed points 1–2 (the page renders; the number
resolves to exactly one account) and **stopped at point 3: no code was sent.**
`app_send_otp` answered 500 *WhatsApp sender is not configured on server*
because the three WASender keys (`wa_plugin_url`, `wa_app_key`, `wa_auth_key`)
are empty on the Uganda install, whose transport is Evolution
(`wasender_configured=false`, `evolution_configured=true`,
`dry_run_mode=false`). **Pre-existing, not Phase 1's:** 5.18.36 (`c82e0b9`)
refused a matched number with the same three-key check, the same 500 and the
same `otp_wa_not_configured` audit row; 5.18.37 only moved the check before the
lookup so every number gets one answer. Points 4–9 were not reached; nothing
was changed; the prerequisite's redesign (accept any configured transport)
belongs to Phase 2. **Consequence recorded:** with the e-mail lookup inert (the
structured index has no e-mail column — pinned by test in this release), the
Uganda customer portal has no working sign-in path today and, as far as the
code shows, had none before 5.18.37; `scripts/phase1-deploy.sh --otp-history`
(read-only counts, no identifier or code) was added to show that from the
production records. The uCRM UI reading (System → Webhooks) is still pending;
`crm_webhook_key` stays unset. *(Resolved 26 Sep 2026: read, no secret field; the key stays unset
by evidence — 5.18.38 record.)* **Two container-log findings, neither Phase
1's:** `dishnet-hybrid-sudan/main.php:456` throws `str_pad(): Argument #1
($string) must be of type string, int given` on every five-minute tick except
the daily pull tick — `declare(strict_types=1)` plus an `(int)` hour, unchanged
since the plugin's first import into this repository (2 Sep 2026); it costs the
*scheduled for* and *total execution* log lines only, the pull and the index
rebuild run on the pull tick, and the one-line fix (`(string)$autoPullHour`) is
proposed for its own window, not applied; and `dishnet-data-report/main.php:105`
(a different plugin, not in this repository) throws a `flock()` TypeError.
**`--otp-history`, 20:49 UTC (`527a69c`), read-only:** the three WASender keys
are empty in the store and in the settings files alike; Evolution is set (three
instances); `dry_run_mode` off. `app_audit_log` holds **four** sign-in rows in
its whole history, all of 25 Sep 2026 — `otp_no_account` 1 (14:28),
`otp_no_account_email` 2 (14:28, and 20:07 = the deploy run's C5 probe),
`otp_wa_not_configured` 1 (20:38 = the closure test) — and no `otp_sent` or
`login_success` ever; `notification_audit_log` holds **zero** `app_otp` rows
ever. No sign-in code has ever been handed to a transport on this install: the
Uganda portal sign-in never worked, and 5.18.37 inherited the dead path rather
than causing it. The `main.php:456` line appears 276 times in the last 24 h and
**230 times on 25 Sep before the 20:07 deploy** (oldest log line kept: 13 Sep),
so the tick's crash predates 5.18.37; other plugins logged 27 fatal lines in
24 h. **Phase 1 is CLOSED** on this evidence; the uCRM webhook-screen reading
stays outstanding and `crm_webhook_key` stays unset. *(Item B resolved 26 Sep 2026 — 5.18.38 record.)* **Phase 2 (authentication)
was approved by the operator on 25 Sep 2026** after this run.

## 5.18.38 — the customer signs in on the tenant's own terms, and stays signed in only where the server says so (audit Phase 2)

**25 September 2026** · `lib/TenantProfile.php` (new), `profiles/south-sudan.json` (new),
`profiles/uganda.json` (new), `lib/PhoneNumber.php` (new), `lib/CustomerJwtKeys.php` (new),
`lib/CustomerSession.php` (new), `lib/ClientSearchIndex.php` (new), `lib/JwtAuth.php`,
`lib/NotificationService.php`, `lib/ConfigVault.php`, `lib/PluginConfig.php`,
`lib/CustomerContact.php`, `lib/EmailTemplate.php`, `lib/OverdueDunningHelpers.php`,
`lib/timezone.php`, `lib/PortalLocale.php`, `includes/api/api_customer_app.php`,
`includes/api/api_customer_support.php`, `tabs/customer_app/login_web.php`,
`tabs/customer_app/portal_data.php`, `tabs/customer_app/portal.php`,
`tabs/admin/app_logins.php`, `webhook.php`, `cron_sync.php`, `public.php`, `manifest.json`,
`tools/set_config.php`, `tools/customer_jwt_key.php` (new),
`migrations/073_customer_sessions.sql` (new), `migrations/074_client_search_index_flags.sql`
(new), `tests/fixtures/fake_evo_server.php`, six new test suites and four updated ones.
Record: the private Phase 2 report handed to the operator; decisions D-1…D-17 taken before
code are in it.

Phase 2 of the customer-login / portal / payments audit — **authentication**, scope
§A.2 row 2 of the approved remediation plan, approved by the operator on 25 September
2026 after the Phase 1 closure.

**1. What was configured (observed on the Uganda install, closure run 25 Sep 2026):**
the three WASender keys empty and Evolution set, so `app_send_otp` refused every number
with 500 *WhatsApp sender is not configured*; `ca_phone_intl()` completing every number
with `+211`, so a Uganda customer's code would have been addressed to South Sudan; the
customer token signed with `sha256(webhook_secret | crm_auth_token | constant)` where both
inputs are empty — a key anyone can read in the source; the token carried in the URL
(`&token=`), in a JavaScript-set cookie and in the page's own script; the portal never
consulting the logout blacklist; the e-mail identifier matching nothing (the structured
index had no e-mail column); a uCRM lead able to sign in. `login_success` has never been
written on this install: no customer has ever held a token there.

**2. Why:** none of those is a configuration matter. A code that cannot leave, a key that
can be computed, a session that outlives its logout and leaks into referrers and another
plugin's URL, and a country written into the code are the four defects the plan's §D–§E
exist to remove.

**3. Exactly what changes** (no stored row is changed; two additive migrations):
- **Transport.** `app_send_otp` asks `NotificationService::phoneTransport()` — Evolution
  where an instance is mapped, else WASender where its three keys are set — and refuses only
  when there is none; the WASender-only gate is gone. The result is read through a public
  getter, not reflection. A login code is never written to the conversation store nor to
  the retry queue; `app_otp_pending` stores an HMAC of the code, not the code.
- **The number.** `lib/PhoneNumber.php` is the one rule (00/+/eleven digits kept as typed;
  a trunk 0 dropped and the tenant's dial code put in front; anything else null, never a
  guess). It carries no dial code; the tenant profile supplies it. `ca_phone_intl()`
  delegates to it and returns `''` for a number it cannot canonicalise, which then matches
  nobody. The identifier a sign-in is recorded under is the canonical `+E.164` number.
- **The tenant profile.** `lib/TenantProfile.php` + `profiles/*.json`, selector
  `tenant_profile` (else derived from the currency exactly as the login hint always was,
  else south-sudan). Resolution per field: explicit key → profile → the reader's literal.
  `profiles/south-sudan.json` is exactly the literals the readers carried, so an install
  that configures nothing is byte-identical (pinned by `test_tenant_profile.php` against
  the constants it replaced and by every existing South Sudan pin); `profiles/uganda.json`
  holds only values already in the repository (`CustomerContact::UGANDA`,
  `set_email_brand --uganda`, the Uganda PDF templates, the knowledge seed) and leaves
  office hours, courts, legal texts and payment instructions null (plan §D.6). Re-pointed
  readers: `CustomerContact`, `EmailTemplate::brand()`, the dunning footer defaults,
  `dn_tz()`, `PortalLocale::dialHint()` (selector only; the currency rule is unchanged),
  and the login page's title and footer.
- **The key.** `customer_jwt_keys` (kid → 64-hex) + `customer_jwt_active_kid` +
  `customer_jwt_key_dates`, generated once at the first request (`CustomerJwtKeys::ensure`
  in `public.php`), vaulted, never printed; `tools/customer_jwt_key.php` shows, rotates
  and prunes without ever printing a secret. `JwtAuth::forCustomers()` signs with the
  active key and puts `kid` in the header and `iss`/`aud` in the claims; `verify()` in
  customer mode requires all three, checks `alg`, and refuses an unknown kid — **so a
  token signed the pre-Phase-2 way is refused from the deploy (E3-a; nobody on Uganda is
  signed out, because nobody ever signed in).** `fromConfig()` stays for the unrouted
  `api/v2/router.php` and for one hand-off (below).
- **The session.** `app_verify_otp` records a `customer_sessions` row (migration 073)
  and sets `dn_customer_session`: HttpOnly, SameSite=Lax, Secure when the request is
  HTTPS (incl. `X-Forwarded-Proto`), Path = the plugin's own path, Max-Age = the token
  lifetime (`app_jwt_ttl_days`, default 30). The JSON carries the token only to a client
  that sends `X-DishNet-Client`; a browser never sees it. The API accepts Bearer (native
  WebView, tests) or the cookie; a cookie may authenticate a non-GET only with
  `X-Requested-With: DishNet` from a same-site request (403 `Cross-site request refused.`
  otherwise). `?token=` is accepted nowhere. Every token must have a live session row:
  logout revokes it (portal and API alike), `staff_revoke_customer_sessions` (admin) ends
  every session of one client, with a control on the Customer App Logins tab.
- **The pages.** The login page completes without writing a cookie and without a token in
  the redirect; a live session that still owes consent lands on the consent step, and the
  portal sends such a session back there; "go back" on consent ends the session. The portal
  embeds no token, appends none to any URL, sends `Referrer-Policy: same-origin` and
  `Cache-Control: no-store`, and downloads PDFs on the cookie (a same-origin GET carries a
  Lax cookie, so the plan's one-time download ticket is not needed). The data-report
  hand-off (another plugin, not in this repository) gets a purpose-bound ten-minute token
  minted on click (`app_data_report_token`, legacy signing, `aud=data-report`) — never the
  session token.
- **The index and the gates.** Migration 074 adds `email`, `is_lead`, `is_archived`,
  `is_active`, `client_type`, `has_service`, `has_invoice`; `lib/ClientSearchIndex.php` is
  the one row builder, used by `cron_sync.php` (the only effective writer) and by the
  webhook's `client.add`/`client.edit` for the verified client. The e-mail identifier
  matches again. `app_send_otp` refuses — with the uniform answer, audited
  `otp_ineligible` — an archived client, a lead unless `portal_login_allow_leads=yes`, and,
  only when `portal_login_require_service=yes`, a client with no service; a NULL flag
  (not yet synced) never refuses. The final business rule is Phase 3's.
- **Declared:** `tenant_profile`, `portal_login_allow_leads`, `portal_login_require_service`,
  `app_jwt_ttl_days` in `manifest.json` and in `tools/set_config.php` (which refuses a
  selector that names no shipped profile). Vault keys gained the three key values and the
  selector; `customer_jwt_keys` is a redacted secret.

**4. Effect on UISP/uCRM:** none on uCRM's side. No uCRM API call is added; the webhook
handlers gain a local index upsert of the entity they already re-read. uCRM will show the
four new optional keys on the plugin's configuration screen and writes `""` for an unset
one, which the code treats as unset. The data-report plugin keeps receiving a token of the
shape it has always been given, ten minutes long.

**5. Rollback:** `git checkout 68f4eeb -- dishnet-hybrid-sudan && bash scripts/deploy-hybrid.sh`
(then return the checkout to the branch). No data step: migrations 073 and 074 are
additive and the older code ignores them; the generated `customer_jwt_keys` stay in the
vault for the next attempt; a Phase-2 cookie is ignored by the older code, so a customer
signs in again — on Uganda there is nobody to affect.

**Applied by:** nobody yet. Built and proved on 25 September 2026; deployment is its own
approval and its own command.

**Status:** **deployed 26 September 2026 02:59 UTC** (`fa2d463`, "✓ container now
serves fa2d463", over live `68f4eeb`) by the one command above, first attempt; backup
`/root/dnb-phase2/backup-20260926T025934Z` (93 MB data directory + 116 KB `data/`). Checks
**63 ok, 1 failed, 0 notes.** The failure is **C7**: one `[DishNet UNCAUGHT] str_pad() …
main.php:456` line in the window — the pre-existing tick crash recorded at the Phase 1
closure (`--otp-history`: 230 lines on 25 September before that deploy), not a 5.18.38 path.
Read off the code after the run: the line sits in the *"auto-pull scheduled for …"* log
branch that every tick takes except the pull hour; the master cron dispatcher (and with it
`cron_sync`) has already run by then, the pull hour does not take that branch, and the only
work after it on an ordinary tick is the final *total execution* log line. Cosmetic, a
one-line cast; its own change, not bundled here. **Posture P:** migrations 073 and 074
recorded; `customer_sessions` present; the index carries the new columns (90 rows, flags
still empty at deploy time — `cron_sync` fills them); the signing key set provisioned (`k1`)
and the three values in the vault; the tenant profile resolves to **uganda / +256** derived
from the vaulted currency (no selector, no currency in the store), so **the one configuration
step was not needed and was not run**; 0 live sessions. **Sign-in L1–L8 with the operator's
own number:** 1 account matched; transport in use `evolution` (WASender unconfigured); the
account passes the gates; `app_send_otp` 200; the code arrived; `app_verify_otp` **200** —
the code typed on the server was accepted (a wrong code fails here with 401); the cookie is
`HttpOnly; SameSite=Lax; Secure`; consent 200; the portal answers **200 on the cookie**, **302**
with a URL token and no cookie, 302 without a session; `app_me` with `?token=` **401**, with the
Bearer token 200; logout 200; the same token afterwards **401 Token revoked**; the old cookie
**401**; the code appears in no container log line, no `webhook_log.json`, no notification,
queue, conversation, pending or audit row, and the newest `app_otp` notification row withholds
the text. **D:** 0 new webhook entries during the run, 0 `entity_unverified` overall; the
endpoint objects are not readable over API v2.1 or v1.0 (404); the uCRM UI reading (System →
Webhooks → the plugin endpoint) was completed the same morning — the closing paragraph of this
record; `crm_webhook_key` stays unset. **E:** `pdf_link_secret` in store and vault; the
pre-5.18.37 link 403, a `PdfLinkToken` link 200, a random token 403. **After the deploy,
from uCRM's own request log (System → Webhooks → Request log, read by the operator at 06:11
EAT):** a client created in uCRM at 06:06 produced `insert`, `invitation` and `edit` events,
each answered **OK (200)**; the edit answered *"No local record for this client (cache
refreshed)"*, which is the branch `webhook.php` reaches **after** the Phase 2 index upsert
(`ClientSearchIndex::upsertClient`, line 2152, before the answer at line 2171), so a client
born in uCRM enters the sign-in index with its e-mail and flags without waiting for
`cron_sync`. The log's request detail shows URL, *Verify SSL certificate*, response code and
phrase, start time, duration, request and response bodies — it is the log view, not the
endpoint form. The endpoint objects answer 404 to `webhooks/endpoints` on this uCRM for the
plugin's own client too (`tools/quote_email_doctor.php` records it), so that form is the only
view. **The endpoint page was then read by the operator (Webhooks → the plugin endpoint, shortly
after the 06:16 EAT test event). It shows exactly five fields — URL · Active (Yes) · Event
types (Any event) · Verify SSL certificate (Yes) · Use delivery window (No) — and no Secret,
Signature, key or authentication-header field. Recorded verbatim, as agreed at the Phase 1
closure: "uCRM cannot supply the Phase-1 optional webhook key through the available interface." `crm_webhook_key` stays unset — by evidence now, not by default — and
the webhook keeps trusting nothing in the posted body: every entity is re-read from uCRM
(5.18.37). Item B of the Phase 1 closure is CLOSED.**

Before deployment the entry read: built, NOT deployed (plugin commit `fa2d463`). Suite 202 suites / 8002 passed / 0 failed on the second run (`phase2-suite-B`; the first run read 8001 / 1, the one failure being `test_links_without_port` flagging the new same-origin check — an allow-list entry, not a weakened guard, then 47/47); weakened copies 19 of 19 caught (M01–M19: legacy-token grace, iss/aud unchecked, session row unchecked, cookie POST without the marker, URL token accepted by the API, token in the body for browsers, consent not enforced on the portal, WASender-only gate restored, +211 hard-coded again, eligibility gates inert, code stored in clear, code into the conversation store, code into the retry queue, token back in the login redirect, default profile uganda ×2, portal accepts a URL token, lead flag inverted, phone helper with a built-in code); migrations rehearsed on a data directory built by 68f4eeb (5.18.37), opened by the 5.18.38 code: `_migrations` 72 → 74 (`073_customer_sessions.sql` 3 statements, `074_client_search_index_flags.sql` 8 statements, 0 errors in `migration.log`); `customer_sessions` created; `client_search_index` 6 → 13 columns; every pre-existing table's row count unchanged (the three `ucrm_*_cache` tables and one index row were added by the rehearsal's own sync); the key set `customer_jwt_keys` / `customer_jwt_active_kid` / `customer_jwt_key_dates` provisioned in the store; the 5.18.37 code still opens the directory.
Production stays on 5.18.37 (`68f4eeb`). Operator decisions taken by default and flagged in
the report: E3-a immediate cut-over; body token only for a self-announcing native client;
30-day lifetime; sessions table; eligibility defaults (leads no, archived no, service not
required). Not done here, by scope: Phase 3 lifecycle, Phase 4 portal content and the
website, Phase 5 payments, the `main.php:456` tick crash.

## 5.18.39 — the five-minute tick no longer dies on its own log line

**What was wrong.** uCRM runs `main.php` about every five minutes. Its auto-pull block decides
whether the daily uCRM client pull is due and, when it is not, logs *"UCRM auto-pull:
scheduled for …"* with the hour padded to two digits by `str_pad($autoPullHour, 2, '0',
STR_PAD_LEFT)`. `$autoPullHour` is an `int` and `main.php` declares `strict_types=1`, so under
PHP 8 the call throws `TypeError: str_pad(): Argument #1 ($string) must be of type string, int
given` — the `[DishNet UNCAUGHT] … main.php:456` line the container log carried on every tick
except the pull hour: 230 on 25 September before the 5.18.37 deploy and 276 in 24 h (Phase 1
closure), and the one line the 5.18.38 deploy's C7 check caught. **Measured consequence:** by
that line the heartbeat, the data-integrity check, the daily-report and backup gates and the
master cron dispatcher — `cron_sync` and every other job — had already run; the pull hour does
not take the branch; the only work lost on an ordinary tick was the final *"main.php total
execution"* line, and the process still exited 0 because the plugin's exception handler
swallows the failure. Cosmetic in effect, but a crash on every tick buries a real one, and the
tick could never report that it had finished.

**The change.** `(string)$autoPullHour` on both lines (456 and 457). Nothing else in
`main.php` changed; manifest 5.18.39.

**Proof.** `tests/test_tick_auto_pull_log.php` (18) runs the REAL `main.php` in a throwaway
copy — `cron/master.php` removed so only the tail of the tick runs and nothing needs a network,
the child's clock set (`php -d date.timezone`) to an hour that takes the "scheduled for" branch
— and asserts: nothing uncaught, exit 0, the branch line written with a two-digit hour
(`03:00`), the tick reaching its last line. Then the control: the pre-5.18.39 expression put
back in the copy dies with exactly the production message at `main.php:456`, exits 0 all the
same, and writes neither line. Then the repository pin: two casts, no bare call. Suite
203 suites / 8020 passed / 0 failed, one run (a one-line change with its own suite; the Phase 2 build was proved twice the day before).

**Deployment (NOT done).** `scripts/deploy-5.18.39.sh`, pinned to plugin commit
`e333261`: the Phase 1/2 machinery (before-evidence, now with the crash rate of the
last hour and 24 h and the count of completed ticks in the heartbeat; backup; the `DEPLOY`
prompt; the documented deploy), then **stage T waits for the next tick**, up to thirteen
minutes, and proves it: a completed tick after the deploy (a "total execution" line newer than
the heartbeat's last line before it), the auto-pull line with a two-digit hour, zero crash
lines of the tick in the container log from 90 s after the deploy (a tick already running the
old code may still die in the first seconds; that is reported as a note), other plugins' fatals
noted and never counted against this build. Rehearsed against a fake docker in four scenarios
(a completing tick, none, a crash after the guard, the pull hour): 7/7.

**Status:** **deployed 26 September 2026 03:58 UTC** (`e333261`, "✓ container now serves e333261",
over live `fa2d463`) by the operator with the one command above, first attempt, **7 ok / 0 failed /
1 note**. Before: 12 crash lines in the previous hour, 276 in 24 h, 0 completed ticks among the last
200 heartbeat lines. After: the first tick to run the new code completed at 04:05 UTC (stamped
`07:05:03` in the heartbeat — see the clock note below), wrote *"UCRM auto-pull: scheduled for
2026-09-27 03:00."* with the two-digit hour, and the container log holds **no crash of
`main.php` from 90 s after the deploy**. The note is the other plugin's pre-existing
`dishnet-data-report/main.php:105` flock TypeError. The superseded guard later added to this
command did not exist when it ran and changed nothing about the run.

**Recorded, not fixed — the heartbeat has two clocks.** Its opening lines are stamped UTC and its
closing lines Kampala time (+03:00): a master-cron job sets PHP's default timezone midway through
the tick, so *"[04:00:11] Heartbeat complete."* is followed by *"[07:05:03] main.php total
execution"* for the same tick. Cosmetic in the log; it matters to anything that compares those
timestamps, which is exactly what the 5.18.40 command did — see that entry.

## Website — Customer Login opens the DishNet portal (26 Sep 2026)

**Decision 8 of the customer-login remediation plan, executed (Phase 4, website half).**
Every "Customer Login" on `dishnetuganda.com` — header, mobile menu and footer of all 57
pages, plus "Open Portal" on the pay page, "customer portal" on the app page and "the customer
login page" in a tutorial: **176 links** — now opens the DishNet portal sign-in,
`https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/public.php?page=customer_login`,
instead of uCRM's own client-zone login `/crm/login`. The sign-in page sends a customer who
already has a live session straight to the portal, so the link is right for first-time and
returning customers alike. The replacement was scripted with the counts asserted (176 in 57,
exactly the audit's numbers; anything else would have changed nothing). `verify-site.sh`
now allows exactly that one portal URL; `README-DEPLOY.md` records the change and closes the
"portal deep-link" placeholder. The site's own checks pass: `verify-site.sh` (404, SEO,
content integrity, commercial rules), `verify-address.py`, `stamp-assets.sh --check`.

**Why now.** The plan gated this on Phase 2 being proved on Uganda, which the 5.18.38 deploy
did at 02:59 UTC. Pay Now exists only in the portal (`docs/32` §7), and the website was the
one door still pointing customers away from it.

**Status: committed, NOT deployed.** The website goes live only when the operator redeploys
it in EasyPanel (project `web`, app `web-uganda`). The 5.18.40 deployment command's stage W
reports whether the live home page already links the portal. The short address
`crm.dishnetuganda.com/customer-login` (plan §G step 4) is **optional and separate**:
`scripts/customer-login-clean-url.sh` places one Traefik file from
`scripts/traefik/dnb-customer-login.yml.template`, verifies the 302, and is undone by deleting
the file. Not run; the website does not depend on it. **Approved by the operator on 26 Sep 2026 and handed over** (commit `4fd6b30`): the file
carries an `https` router and an `http` router for `Host(crm.dishnetuganda.com) && Path(/customer-login)`
sharing one `redirectRegex` middleware that matches both schemes, in the shape of `traefik-mail.yml`
and the staging routes (explicit priority read from `uisp.yaml` plus 10, or none when it sets none;
`tls.certResolver` on the https router only); the redirect is a 302 until the address is stable.
Rehearsed with `docker` and `curl` as exported bash functions and a fake `uisp.yaml`, four
scenarios — a completing route (priority 10 → 20, resolver read, valid YAML), no priority in
`uisp.yaml`, a route Traefik never takes (both checks fail, the file is kept for the operator's
rollback decision), a wrong Location (exactly one failure): **REHEARSAL: 12 ok, 0 failed**. The first rehearsal
run read 11 ok / 1 failed because one assertion counted the word *priority* in the file's own
comment lines; the assertion was corrected, the command was not. That first run had already been
committed as `4fd6b30`, whose message says 11/11 — that figure was written before the run finished
and is wrong; this paragraph is the record. **RUN on the server 26 September 2026 04:30:40 UTC by the
operator, first attempt, 7 ok / 0 failed / 0 notes:** `uisp.yaml` carries priority 10 and resolver
`letsencrypt`, so the new routers took priority 20; before the file, `https://…/customer-login` answered
404 and the `http://` form 301; two seconds after the file was written both forms answered **302 with
exactly the canonical sign-in as Location**, the canonical page and uCRM's own login still 200,
Traefik not restarted. **The short address `https://crm.dishnetuganda.com/customer-login` is live.**
Rollback remains `rm /etc/easypanel/traefik/config/dnb-customer-login.yml`. The website still links
the canonical long URL (plan §G step 5, switching it to the short one, is optional and not done).

## 5.18.40 — the customer portal can be installed on a phone

**What.** The customer portal gets its own web-app manifest, `?page=customer_manifest`
(`includes/routes.php`, beside the staff app's): name DishNet, start at the sign-in page,
scope the plugin directory, standalone display, the portal's colours, the icon the staff app
already generates at 192 and 512 px. Both customer pages link it (`login_web.php`,
`portal.php`), which with the iOS meta tags they already carried makes "Install app" / "Add to
Home Screen" appear in Chrome, Edge, Samsung Internet, Firefox and Safari. The portal's
settings view gains an **Install the DishNet app** row that shows only where installing is
possible and not already done: it appears when the browser hands over its install prompt
(`beforeinstallprompt`) or on iOS Safari with the Share-menu words, and never inside the
Android wrapper or a window already running standalone.

**Deliberately no service worker for customers.** A cache of signed-in pages on a shared phone
is a data exposure; modern browsers install from the manifest and meta tags alone. Pinned by
the test. (The staff app's service worker is unchanged. Its scope is the plugin directory, so
on a staff member's own browser it also fronts the customer pages — pre-existing, staff
devices only, recorded here, not changed.)

**Also carries 5.18.39** (the tick fix). 5.18.39 did go live on its own at 03:58 UTC (its entry);
its command now stops with a pointer to the 5.18.40 command, so it cannot be run against a later
build.

**Proof.** `tests/test_customer_pwa.php` (31) serves the plugin the way uCRM does, under
`/crm/_plugins/dishnet-hybrid-sudan/`, and asserts the manifest (200 without a login, the
type, every field, start_url inside scope, both icons answering as images, no
credential-shaped word), the sign-in page's link, the portal's link and Install row, no
service worker on either page, the portal still refusing without a session, the staff
manifest unchanged — and the control: with the link removed from the copy, the served page no
longer carries it. Suite: run A 204 suites / 8049 passed / 1 failed — the one failure was the new test's own first version, whose HTTP client followed the portal's redirect and so read a 200 where a 302 was the answer; corrected (`follow_location` off, the 302 and its Location asserted) before run B; run B 204 suites / 8051 passed / 0 failed.

**Deployment (NOT done).** `scripts/deploy-5.18.40.sh`, pinned to plugin commit
`4a2f41c`: the Phase 1/2 machinery, then **M** the manifest over the public address
(200, the type, standalone, start_url, scope, icons as PNG, the sign-in page's link, no service
worker, the portal still refusing), **T** the wait for the next tick (5.18.39's proof), **W**
whether the live website already links the portal (report only). Tick stage rehearsed against a
fake docker: 7/7.

**Status:** **deployed 26 September 2026 04:21 UTC** (`4a2f41c`, "✓ container now serves 4a2f41c",
over live `e333261`) by the operator with the one command above, first attempt. **Stage M, all
eleven checks passed over the public address:** the manifest 200 without a login as
`application/manifest+json`, standalone, start_url the sign-in page inside the scope
`/crm/_plugins/dishnet-hybrid-sudan/`, icons 192 and 512 declared and answering as PNG, the sign-in
page linking the manifest, no service worker, the portal answering 302 without a session. (Before
the deploy `?page=customer_manifest` answered 302, not 404; the wording that expected 404 was a
guess and is corrected.) **Stage T in this run: T1 and T2 are NOT evidence.** They accepted the
tick from the 5.18.39 run (`07:05:03`, before this deploy) because the comparison was by timestamp
and the heartbeat's clock changes mid-tick (the 5.18.39 entry's clock note): `07:05:03` reads later
than the UTC-stamped `04:20:03` snapshot line although it is earlier. T3 (crashes since deploy +
90 s, from the container log's own UTC clock) and the 5.18.39 run's whole stage T remain valid,
and 5.18.40 carries the same `main.php`. **Corrected in the command the same hour:** stage T now
looks for closing lines that were absent from a pre-deploy snapshot of the heartbeat, never at
timestamps; rehearsed in five scenarios including this exact case (an old closing line in a
later-looking clock, nothing new → T1 fails): 8/8. Two cosmetic warnings ("ignored null byte") came
from the icon bodies read into a shell variable; binary bodies are now stripped of NULs. **The
run's T3, W and summary were still pending when this was written**; they are recorded from the log
file when it arrives. The website was not yet redeployed at the time of the run.

## 5.18.41 — the portal speaks the tenant's identity; consent belongs to the customer; one public address (docs/38 change set A1)

**Why.** The read-only customer-journey audit (docs/37, docs/39) measured, on the Uganda install, a Support
tab with 23 South Sudan literals and no Uganda contact, `+211` in every page's script, a Juba default, a
South Sudan bank and " USD" on the invoice screen, Juba in the legal pages' footer; the same customer asked
for consent again on the e-mail route after accepting by phone; and the same plugin answering on
`:8443`, where UISP's certificate is self-signed for `localhost`. The operator adopted the remediation plan
(docs/38): *"i will go with your recommendation"*.

**What.**
- **A1.1** `tabs/customer_app/portal_data.php` loads `TenantProfile` once and exposes what the views need;
  every view reads variables, never the profile. `portal.php`: the Support tab's WhatsApp card, "Call us"
  (**shows and dials the same number**, the profile's support phone — docs/38 decision A-1), the e-mail
  row; the 16 WhatsApp sites dial one emitted constant `DishNet.supportWa`; the status view's locality; the
  **Fiber** and **4G LTE** cards render only where the profile's `products` lists them; the invoice screen's
  bank-transfer block prints only the profile's own `payment_instructions` (South Sudan's, made explicit in
  its profile; **none for Uganda**, never the other tenant's bank) and repeats the currency code only when
  the symbol does not already carry it; the payment notification the same. `legal_page.php` footer, WhatsApp
  and e-mail from the profile; `lib/LegalContent.php`'s **contact lines** read the profile — its identity,
  jurisdiction and regulator sentences are **still South Sudan's on every install** (change set A2, gated
  on approved wording, docs/38 §7.3). `TenantProfile` gains `contact()`, `products()`, `sells()`,
  `formatWa()`. South Sudan renders what it rendered, except the one difference above.
- **A1.2** `CustomerSession::hasCurrentConsent($pdo, $identifier, $clientId = 0)`: a row at the current
  versions for the identifier **or** for the customer (`crm_client_id`, written only by `app_record_consent`
  under a session the OTP proved for that customer). The login response, the portal and the login page pass
  the session's `sub`. Recording unchanged; a row for another customer never admits; a version bump re-asks.
- **A1.3** `lib/CanonicalHost.php`, called once in `public.php` before the routes: with `crm_public_url` set,
  a GET/HEAD for a customer page (`customer_login`, `customer_portal`, `terms`, `privacy`,
  `customer_manifest`) whose `Host` names the public host **with an explicit, different port** answers
  **302** to the public address, same path and query. Never `page=api`, never a POST, never the native
  wrapper (`X-DishNet-Client`), never another host name, never a `Host` without a port — so the public
  origin can never match and a loop is impossible by construction. No override (South Sudan): nothing.

**Found while building** (docs/38 §7.1): a closing PHP tag inside a `//` comment ended PHP mode and printed
the rest of `portal_data.php` into the home page (caught by the rendered-page test; now a tokenizer-based
guard with its own control); PDO binds an int as TEXT and SQLite orders TEXT above INTEGER, so a `? > 0`
guard was true for `'0'` (the lookup now branches in PHP and binds an integer); a customer token's issuer is
the plugin's directory name; opcache serves an edited copy up to two seconds late, so every control that
edits a served copy polls.

**Proof.** New suites, each rendering the real pages under `php -S`: `tests/test_portal_tenant.php` (88:
Uganda pages carry nothing of South Sudan and carry the Uganda contacts; the south-sudan control; a scan of
the sources for any literal that is not a fallback argument or a named A2 exception; the planted-literal
control; the closing-tag guard), `tests/test_consent_identity.php` (28: the rule on the function, then over
HTTP — the phone route consents, the e-mail route of the same customer passes, another customer does not, a
version bump re-asks both), `tests/test_canonical_host.php` (49: the rule on arrays, then over HTTP with and
without the override, the loop check, the wrapper, the API, a POST, the control that the redirect comes from
CanonicalHost alone). **Eleven weakened copies, each caught** (a literal number back in one button, Juba
back, the footer back, the bank block for every tenant, the fibre card for every tenant, the Terms contact
literal back, consent ignoring the customer, the entry point not calling CanonicalHost, CanonicalHost
redirecting `page=api` / a Host without a port / POSTs). Journey rehearsal against the sandbox: **77/77**,
with L10 (the A2 wording) the only tenant finding still expected to fail. Suite run A: **207 suites /
8,216 passed / 0 failed**; run B: **207 suites / 8,216 passed / 0 failed** — identical.

**Deployment (NOT done).** `scripts/deploy-5.18.41.sh`, pinned to plugin commit `a2ea19f`: the
5.18.40 machinery, then stage **V** over the public address and the `:8443` door — the public address must
answer with **zero redirects** (else the script rolls back by itself), the sign-in / Terms / Privacy pages
carry no South Sudan contact, `:8443` answers 302 to the public address for the customer pages and never for
`page=api`, a POST or the wrapper, no fatal since the deploy. The signed-in screens are then proved by the
operator's own `journey-audit.sh --login-phone` (L5 and L9 pass; L10 fails until A2). Rollback: redeploy the
previous commit; consent rows written meanwhile stay valid.

**Also handed over, not run:** change set **C-1** as `scripts/dnb-crm-root-redirect.sh` (one Traefik file for
the bare `/crm`, read-first, verified, rehearsed 17/17 in `scripts/harness/crm-root/`), the **C-2**
checklist and the **A2** wording proposal (docs/38 §7.2–7.3). **B-1 decided: O3** (docs/39 §13–14).

**Status: LIVE on the Uganda install, 26 September 2026.** The operator's run of the pinned command at
15:04:39 UTC found the container already serving `a2ea19f` (deployed by the operator shortly before; that
run's log is in `/root/dnb-5.18.41/` if the script was used) and ran the verification alone: **16 ok, 0
failed, 2 notes** — the public address answers 200 with zero redirects; the portal without a session still
302s to a Location without `:8443`; the sign-in, Terms and Privacy pages carry no South Sudan contact and the
Terms page carries the Uganda WhatsApp link and locality; `:8443` answers 302 to the public address for the
sign-in page and the manifest, 200 for `page=api` and for the wrapper's request, 401 (never 302) for a POST;
no fatal since the deploy. The two notes are the expected ones: the Terms page still names South Sudan ×2 and
Juba ×2 (change set A2). **The signed-in walk was not run yet:** the operator passed the placeholder text of
my message as the number, and the script tried it (`STOP: staff_login_lookup → 000`). The audit script now
refuses a non-number argument up front, and every command text shows `+2567XXXXXXXX`.

**C-1, attempt 1 at 15:05:08 UTC — FAILED on the script's own defect; nothing else changed.** The
generated Traefik file carried the hostname's dots escaped with a single backslash inside the YAML
double-quoted regex (the script produced a backslash pair, and sed's replacement halves a pair); that is
not a valid YAML escape, so Traefik rejected the whole file and the bare `/crm` kept answering
`301 → …:8443/crm/`. Everything around it held (`/crm/` unchanged, the portal and uCRM's login 200, Traefik
not restarted), and the run also recorded that `uisp.yaml`'s host router carries both
`crm.dishnetsudan.com` and `crm.dishnetuganda.com` at priority 10 (the new router sits at 20 and names the
Uganda host only). Reproduced here by parsing the file exactly as written. Fixed the same hour: the dots are
written as `[.]` (no backslash at all), the file is parsed as YAML with python3 **before** it is placed (a
lone backslash or a parse failure removes the temporary file and stops), and Traefik's own log is shown and
counted when the route is not taken. The rehearsal now parses the YAML, carries the broken-copy control (the
26 September defect is refused before placement) and a Traefik-rejection scenario. **The re-run of the same
command rewrites the file** — the rejected copy in the config directory is replaced, not left beside.

**C-1, attempt 2 at 15:21:06 UTC — THE ROUTE IS IN PLACE.** The generated file parsed as YAML, Traefik took
it within 2 s without a restart, and the bare `/crm` now answers **302 → `https://crm.dishnetuganda.com/crm/`**
on both the https and the http form; `/crm/` answers exactly as before, the portal sign-in and uCRM's login
still 200. One check failed: the script counted **two Traefik log lines naming the file** since it was written
and — a second defect of the script — printed them only in the branch where the route is NOT taken, so
nothing was shown. Fixed the same hour: every line naming the file is printed whatever the route did, and the
rehearsal carries that scenario (33 checks). Also that afternoon: the operator typed the example shape
`+2567XXXXXXXX` into the sign-in walk and the script refused it as designed; the walk is still to be run with
the real number.

**C-1 — the two Traefik lines READ (15:21:07Z) and explained; the route is healthy.** The operator ran the
read-only log command: both lines are `ERR … /data/config/dnb-crm-root.yml: yaml: line 38: found unknown
escape character providerName=file`, both stamped **15:21:07Z** — the second the re-run **staged its
temporary file** (`dnb-crm-root.yml.tmp`) inside Traefik's watched directory, one step before the `mv`.
Traefik re-parses every file in that directory on any event, so what it parsed at that moment was the
**attempt-1 file still lying there** (the one with the invalid escape, line 38 being its `regex:` line);
the error is the old file's, logged once per event. The attempt-2 file parses (reproduced here with PyYAML,
line 38 `regex: "^https?://crm[.]dishnetuganda[.]com/crm/?(\\?.*)?$"`), and the router it declares exists —
the 302 was measured on both the https and the http form. Nothing is wrong on the server. Script fix, the
third: the temporary file is staged in the **parent** directory (same filesystem, one atomic move, one event
carrying the final content) and the timestamp the log is read from is taken before the move; the rehearsal
asserts both (35 checks) and that no temporary file is ever left in the watched directory. The sign-in walk:
after the operator pasted three different placeholders as the number, `journey-audit.sh --login-phone` and
`--login-email` now **ask for the value on the terminal** when it is missing or is not one — typed without
echo, so a copy of the terminal cannot carry it, confirmed back masked (`+…217`, `b***@…`), never printed;
without a terminal the usage message and exit 64 as before.

## 5.18.42 — the legal documents say the tenant's country; each tax on the invoice has its own line (docs/38 change set A2, §7.5)

**Why.** The operator approved the A2 wording proposal of docs/38 §7.3 (*"i will go with your recommendation"*,
26 September 2026) and asked, the same day, to *"separate the UCC tax and other details so customer can
understand properly"*. 5.18.41 had left the Terms and Privacy Policy's identity, jurisdiction, product, fee
and regulator sentences as South Sudan's on every install (pinned, deliberately, until wording was approved);
and the invoice screen's "Tax" row read a field a uCRM invoice does not have.

**What.**
- **A2 — `lib/LegalContent.php` is a TEMPLATE over the tenant profile.** Every sentence that names a company,
  a country, a product line, a fee, a court or a regulator is composed from the profile's new `legal` block
  and its other facts (`legal_entity`, `country.name`, `jurisdiction.law`, `jurisdiction.courts`); the file
  carries **no tenant's wording of its own** — a test scans it for South Sudan's and for Uganda's. Where a
  profile does not answer, the sentence falls back to a **neutral, fact-derived** form (the country's name,
  "internet services"), never to another tenant's wording. `profiles/uganda.json` carries the approved text
  (points 1, 2, 4, 6, 8, 9, 10 as proposed; **3, 5, 7 in the conservative form and flagged** in docs/38 §7.3:
  *"the courts of Uganda"* with no court named; no data-protection law named; **no fee stated** because no
  Uganda figure is confirmed — the Billing and Starlink-transfer sections point to the quotation and the
  invoice). `profiles/south-sudan.json` carries exactly the sentences the code printed before, so the install
  that configures nothing renders **byte for byte** what it rendered (golden sha256 `b2f4ff3b…ead4637`,
  computed from `a2ea19f` before the template was written).
- **The version and date are the tenant's.** `dnLegalVersion(TenantProfile $tp)` — the parameter is
  **required**, so no caller can compare a Uganda row against another tenant's version by leaving it out
  (that would re-ask on every sign-in, forever). Threaded through the sign-in page, the legal pages, the API's
  login response, `app_legal_version`, `app_record_consent`, the portal and
  `CustomerSession::hasCurrentConsent($pdo, $identifier, $clientId, $tp)`. **Uganda 1.1 / 1.1, dated
  26 September 2026; South Sudan 1.0 / 18 April 2026.** Consequence on deploy: every Uganda customer is asked
  once to accept the new documents on the next sign-in; no South Sudan customer is asked.
- **The invoice's taxes — `lib/InvoiceTotals.php`.** Measured: `portal_data.php` and `app_invoice` read
  `totalTaxes`; a uCRM invoice carries `subtotal`, `taxes[] = [{name, totalValue}]`, `totalTaxAmount`,
  `totalDiscount` (probe-confirmed shape, the live install's invoice #1 verbatim in `test_efris_mapper.php`).
  So **no tax line ever rendered**. Now the totals block prints, as uCRM states it: *Before tax*, **each tax
  or levy on its own line under uCRM's own name** (`VAT 18%`, `UCC levy 2%`), *Discount* when there is one,
  *Total*, Paid, Amount due, and one sentence saying so. The app API returns `taxes[] {name, amount}`, the
  corrected `tax` total, `subtotal` and `discount`. **Nothing is computed by the plugin** — no rate, no
  derived amount — so the screen cannot disagree with the invoice document uCRM issued. An invoice with no
  tax line shows the total only. **The other half is an operator act in uCRM (docs/38 §7.5):** create the
  taxes, set inclusive/exclusive pricing, mark the items taxable, check the PDF template — the rates and
  whether the UCC levy is passed on are the accountant's call; the repository asserts neither.
- `tools/tax_probe.php` gains **section 5** (read-only): for the latest invoices, exactly the tax lines the
  invoice screen prints — so what a customer will see can be read before anyone opens the portal.

**Proof.** `tests/test_portal_tenant.php` **107** (the approved Uganda sentences present; none of the other
tenant's law, fee, court, product or regulator; the South Sudan golden; `app_legal_version` per tenant; the
consent step's version; the invoice screen's two tax lines by name and the API's `taxes[]`; the South Sudan
invoice without a tax line as the control; the source scan with **no exceptions left** — LegalContent.php
carries neither tenant's wording). `tests/test_consent_identity.php` **34** (the same rows judged under both
profiles; a version bump **in the tenant's profile** re-asks both routes; `app_legal_version` reports it).
`tests/test_tenant_profile.php` **108** (the open questions are `legal.fees`, `legal.transfer`,
`legal.data_protection_law`, `jurisdiction.courts`, `office.hours`, `payment_instructions`). **Twelve weakened
copies each fail their test** (the Uganda version back to 1.0; the check ignoring the passed profile; the
identity falling back to South Sudan; the version not read from the profile; the sign-in page showing the
default version; the forum naming Juba; the regulator reverting; one word changed in the South Sudan profile;
`totalTaxes` read again; one lumped "Tax" line; the API dropping `taxes`; the lines losing their names).
Suite: **207 suites, exit 0, twice**; the 186 suites that print totals report **8,241 passed / 0 failed** on both runs. The deploy command's stage V2 was rehearsed against a local Uganda sandbox (12 ok) and a South Sudan one (10 of the 12 fail — the checks discriminate).

**Deploy.** `scripts/deploy-5.18.42.sh`, pinned to the plugin commit `d857ec8`; stage V checks the Uganda wording and the
1.1 version on the public pages. **Not deployed by this session** — the operator runs it and sends the log
file (docs/38 §7.2 item 0).

**Status: LIVE on the Uganda install, 26 September 2026, deployed 19:56:26 UTC by the operator.** The run found
the container on `a2ea19f` (5.18.41), took a backup (`/root/dnb-5.18.42/backup-20260926T195609Z`: the data
directory 94 MB, the plugin's `data/` 116 KB, UISP health recorded) and deployed `d857ec8`: **25 ok, 0 failed,
0 notes.** Before and after, measured by the same checks:

| | before (5.18.41) | after (5.18.42) |
|---|---|---|
| the Terms page: `South Sudan` · `Juba` | 2 · 2 | **0 · 0** |
| the approved Uganda identity, governing law, forum | absent | **present** |
| `USD 25` · `USD 150` · fibre · LTE on the Terms page | present | **0** |
| the Privacy page: the UCC sentence, both sign-in channels, no Splynx | — | **present / absent as approved** |
| `app_legal_version` | 1.0 | **1.1 / 1.1** |

The public address answered with zero redirects, the `:8443` door behaved exactly as under 5.18.41, and the
container logged no fatal. **The log's last line reads "5.18.41: PASSED"** — a literal left in the script's
closing lines; the header, every check and the summary above it are 5.18.42's (`d857ec8`). Fixed in
`deploy-5.18.43.sh`, which prints the version it deploys.

**The tax probe, read-only, the same evening** (`tools/tax_probe.php`): uCRM defines **no tax rate**; the
pricing-mode setting is not on the settings endpoint; `taxable` is empty on all 30 products and all 5 plans;
**no product is a UCC or regulatory charge**; the five latest invoices (000001–000005) carry **no tax or levy
line**, and 000005 carries a 30 % discount (2,498,000 − 749,400 = 1,748,600). **The operator's decision,
verbatim: *"ok keep price as it is"* and *"its ohk the way it is"*.** Prices stay as they are and uCRM gets no
tax configuration. The invoice screen therefore shows the total, and the discount where there is one. The
per-tax lines 5.18.42 built stay dormant until uCRM carries a tax, and the AI's rule is unchanged. The
`[ConfigVault] restored after re-install: dpo_…, pdf_link_secret` line the probe printed is the in-memory
gap-fill every command-line load performs (docs/37 §I). The vault file is rewritten only when its content
changes, so nothing was written.

## 5.18.43 — an invoice's first total row reads "Subtotal" (docs/38 §7.5)

**Why.** 5.18.42 labelled the first row of an invoice's totals "Before tax". Measured on the live install the
same evening, invoice 000005 carries a 30 % discount and **no tax**, and the operator decided to keep prices as
they are. On that invoice, and on every discounted invoice from now on, "Before tax" read as a tax still to
come. 5.18.42 also printed the discount after the tax lines.

**What.** `tabs/customer_app/portal.php`: the first row reads **Subtotal**, and the **discount follows it
directly**, before any tax line, then the total. For invoice 000005 the column now reads Subtotal
2,498,000.00 · Discount −749,400.00 · Total 1,748,600.00, the invoice's own arithmetic. The explanation moved
from an HTML comment into a PHP comment: an HTML comment is sent to the customer's browser, and the first draft's
comment put the words "Before tax" back into the page, which the new test caught. `tools/tax_probe.php` section 5
prints the same order. Nothing else changes: no tax is computed, the app API's fields are the same, A2 is
untouched, and the legal version stays 1.1, so no customer is asked to accept anything again.

**Proof.** `tests/test_portal_tenant.php` **112**: the live shape of invoice 000005 (Subtotal, Discount, Total,
in that order; no "Before tax", no tax line, no tax note; the app API's subtotal, discount, empty `taxes` and
zero `tax`), and a synthetic invoice with a discount **and** a tax pinning the order Subtotal, Discount, tax
line, Total. **Four weakened copies each fail it:** the label back to "Before tax", the discount after the tax
lines, one lumped "Tax" line, and the explanation back in an HTML comment. Suite: **207 suites, exit 0, twice; the 186 suites that print totals report 8,246 passed / 0 failed on both runs.** The deploy command's stage V2 was rehearsed against a local Uganda sandbox (12 ok) and a South Sudan one (10 of 12 fail, the control).

**Deploy.** `scripts/deploy-5.18.43.sh`, pinned to the plugin commit `04155df`; stage V re-checks everything
5.18.42 checked. The label itself is seen only by a signed-in customer with a discounted invoice, so the test
suite is its proof. **Not deployed by this session.**

**Status: LIVE on the Uganda install, 26 September 2026, deployed 20:27:30 UTC by the operator.** The run found
5.18.42 (`d857ec8`) live and took a backup (`/root/dnb-5.18.43/backup-20260926T202714Z`: the data directory
95 MB, the plugin's `data/` 120 KB, UISP health recorded). It then deployed `04155df`: **25 ok, 0 failed,
0 notes.** The before-evidence already read the A2 state: on the Terms page `South Sudan` ×0, `Juba` ×0 and the
Uganda identity ×1, and `app_legal_version` 1.1. Every stage-V check held afterwards, so nobody was asked to
accept anything again. The log's last line now reads *"5.18.43: PASSED"*, the version the script deployed.

**The signed-in walk on 5.18.43 (operator, 20:33 UTC, `journey-audit.sh --login-phone`): 33 ok, 0 failed, 6
notes.** L5 (no South Sudan branding), L9 (the Support tab) and **L10 (the Terms page)** all pass; every portal
page rendered 200 with the session; logout revoked the cookie and the PDF link; the code and the cookie are
absent from the container log. Notes, all known: L11 the invoice PDF still carries `crm.dishnetuganda.com:8443`
×2 inside the document (C-2); Usage not implemented (B-4); no kit bound to this customer, so Equipment,
Starlink and WiFi are partial (B-1/B-5); the Data Report hand-off link answers 404 (docs/36 §0).

**Two audit-tool defects the walk exposed, fixed in `scripts/journey-audit.sh`, no plugin change:**
- **The Legal line printed "accepted NULL"** from a field `app_legal_version` never returns. It could never
  have printed anything else. It now prints the tenant's current versions and date. A new check, **L4**,
  reports the server's own verdict from the verify answer (`needs_consent`): *"this customer has already
  accepted the tenant's current Terms v1.1 and Privacy v1.1"*, or a note that the portal will ask. The
  audit still never accepts on anyone's behalf. On this walk every page rendered, and the portal renders
  only after a v1.1 acceptance, so this customer accepted v1.1 on the web after 19:56 UTC.
- **The invoice lines printed "—"** for fields the API returns under other names. They now read
  `invoice_number`, `amount`, `amount_due`, `subtotal`, `discount` and the tax lines. The list line sums
  `amount_due`, because the list never had an `unpaid_total` key. Both were checked against the API's
  real answer shapes here.

**C-2 approved by the operator** (*"i will go with your recommadation"*). It is made by hand in uCRM's
settings. `scripts/dnb-c2-check.sh` is a new READ-ONLY guard that runs before and after the change, and
counts the routers on `:8443` so a mistake shows within minutes (docs/38 §7.2 item 3; rehearsal
`scripts/harness/c2-check/rehearse.sh`, 29 checks). **The kit question was answered *"yes correct"*, read as
"both"**, to be confirmed by the read-only `--chain 7/47/69` runs before any B code (docs/38 §7.4).

**20:45 UTC, the operator's runs.** The **chains for #7, #47 and #69 confirm "both"**. Each kit is typed on the
customer's uCRM service and held in Finance, and uCRM, Finance and Data Report name the same customer for each.
#7's chain is complete, #47 lacks a service line, and #69's kit is not in the hybrid's stock. The order is
revised: staff put the kits into the register first, and the B.3 code waits for a sibling to consume it
(docs/38 §7.4). **The C-2 guard's first run** found uCRM still on `:8443` 17 seconds after `--before`, so the
setting had not been changed yet. Its router count read 0 **with no positive control**. The guard now holds its
own test connection to `:8443` while counting, so a zero is either *measured*, with no router connected, or
*blind*, with the count unable to see the port. It never reports a bare zero again (rehearsal 40 checks).

**20:54 UTC, the C-2 guard's second run.** With the control in place, `--before` saw 8 connections on `:8443`,
all from the server's own or private addresses, and **none from a public address**. That is a **measured
zero**: no router is connected to UISP over the Internet, so C-2 has none to disturb. A device on a private
network or VPN would be among the 8; that limit is recorded. `--after` ran 17 seconds later, as the first run
did, and uCRM was still on `:8443`. Nothing broke, and **the setting has not been changed**. The guard makes no
change; the two fields are edited by hand in uCRM's web page between the two commands. It now says so at the
top of its steps. An `--after` that finds the address exactly as recorded under three minutes earlier says it
changes nothing and names both cases, not yet changed or saved and not yet rewritten (docs/38 §7.2 item 3;
rehearsal 48 checks, two weakened copies caught).

**21:01 UTC: C-2 as planned is not available; C-2b replaces it.** The operator's `--before` at 21:01 again
measured no public connection on `:8443`. Their screenshots show UISP 3.0.159's Settings → General with the
hostname (`crm.dishnetuganda.com`, already right) and **no port field**. uCRM's `:8443` is the port UISP was
installed with; Ubiquiti documents `--public-https-port` for this case (read through a search engine; the page is
blocked from this session). That means re-running UISP's installer: **not recommended, not approved, and not
needed.** The two `:8443` links inside Uganda invoice PDFs turned out to be **the plugin's own Uganda invoice
template**, which prints uCRM's `invoice.onlinePaymentLink` as its PAY NOW button and again as text. docs/37 and
docs/39 said it printed no link; both are corrected. **C-2b:**
- **The fix.** PAY NOW → `https://dishnetuganda.com/pay` (the profile's `pay_url`: Airtel Money and the portal).
  That is three edits in uCRM's template editor, cloned first for rollback, after one READ-ONLY run of the new
  `scripts/dnb-c2-check.sh --links`. That run shows the masked links, the template in use, which payment
  options uCRM's own page names, UISP's installed ports and the pay page's answer.
- **The repository.** Its template carries the same edits, pinned by `tests/test_invoice_template_links.php`
  (17, with a control).
- **The rehearsal.** 94 checks, twice; six weakened copies caught; one vacuous assertion found by its control
  and rewritten. uCRM's payment page is opened from the host, the way a customer reaches it. The plugin suite passes: 8,263 assertions, 0 failed.
- **Not changed.** No plugin release, no deployment and no server change; the uCRM template edit is the
  operator's act.

**21:35–21:40 UTC: `--links` on the server, and the two exported templates change the fix.**
- **What `--links` found.** The two `:8443` links in invoice 000003 are uCRM's online-payment link. uCRM's page
  offers none of the 14 payment options. UISP's public port is unset (`HTTPS_PORT=8443`, `PROXY_HTTPS_PORT`
  empty). The pay page answers.
- **#1000 "Invoice Ugadna" is the South Sudan invoice.** The template Uganda invoices use prints Juba, +211,
  dishnetafrica.com, "Amount Due (USD)" and the USD late-payment terms. Its PAID check (`== '$0.00'`) never matches
  UGX, so paid invoices read UNPAID.
- **#1001 "V1 invoice" is the Uganda template, unused.** It is byte-identical to the repository's of 8 Sep.
- **The fix, v2.** The repository's Uganda template, now v2, is to be pasted over #1000 with the CSS unchanged.
  v2 sends PAY NOW to the pay page and decides PAID from the digits.
- **The proof.** `scripts/harness/invoice-template/rehearse.sh` renders it with real Twig 3.30 and 2.16 under a
  sandbox limited to what V1 uses: 21/21 each, and two weakened copies are caught.
- **The organization record.** `--links` now also shows the organization, phone and e-mail masked, because v2
  prints its address. The plugin test pins the digit rule (21).
- **Not changed.** No release and no server change; the paste is the operator's act (docs/38 §7.2).
- **22:00, the ZIP.** The operator asked for a ZIP to upload. `template-invoice-uganda-v2.zip` (sha256
  `df2cc831…fdff8a52`) is built like uCRM's own exports, and both entries are byte-identical to the repository.
  `--links` now also shows which template the organization gives new invoices (rehearsal 102).
- **21:57, after the upload.** uCRM lists #1002 "v2", and #1000 "Invoice Ugadna" is gone from the list. The
  organization record is Ugandan (Kampala, +256, dishnetuganda.com, TIN, Reg. No), so v2 prints Uganda details.
  The API does not say which template new invoices use. Invoice 000003 keeps its 21 Sep PDF with the `:8443`
  links. `--links` no longer prints the fix for an invoice whose template is gone, and it flags invalid templates
  (rehearsal 109).
- **22:01, confirmed on the server.** The new verdict prints for 000003 and no fix is printed; no template is
  flagged invalid. Waiting on the screenshot of the organization's invoice template and on the next invoice.
- **After 22:01, the operator confirms** that the organization's invoice template is now "v2" (their statement;
  no screenshot). The one proof still to come is `--links` on the next invoice for client #1, which should end
  "C-2 is done for this invoice". uCRM's plugin page reads *5.18.27* while 5.18.43 is verified on disk and live. It most likely
  keeps the version from the last ZIP upload through that screen.

## 26 Sep 2026 — the WhatsApp AI on unlimited data for a business, and on covering another area (docs/40)

**A check, not a change.** The operator asked how the AI answers two things: a business that needs unlimited data
(a Residential plan, not a Business plan with a GB block), and a customer who wants another area covered (uCRM
now prices an outdoor access point and a MikroTik).
- **Measured from the live code (5.18.43).** The prompt was rendered through `getProducts` → `BrainContext` →
  the brain, with a sample price list. No model call was made; this session has no AI key.
- **What works.** Business plans are held back unless the customer needs a public IP or names one. The
  qualification rules make the higher-capacity Residential plan the default. The priority-data note is appended
  in code.
- **A-1, A-2 — "unlimited".** The AI is never told that the Residential plans are unlimited. Its four uses of the
  word are rule 2 ("do not describe a null field as unlimited"), two about Business standard data, and one that
  names no plan. The two knowledge sentences that say it (`BUSINESS_PLANS`, `MANY_USERS_HOTSPOT`) fall beyond
  `KnowledgeBase::promptBlock`'s 600-character cut. Six seeded facts are cut in all.
- **B-1 … B-5 — coverage.**
  - B-1: the access point and MikroTik land in HARDWARE, and no rule links "cover another area" to them.
  - B-2: the price check refuses a total that multiplies a quantity; the customer gets the fallback.
  - B-3: nothing keeps them out of a home total.
  - B-4: the sales number never sees ACCESSORIES, because `BrainContext` drops them; the support number does.
  - B-5: a total may combine only the first six HARDWARE items.
- **The documented live test does not test Uganda.** `tests/conversation-suite.php` builds the brain without the
  knowledge base, so it runs the South Sudan coverage block. It also skips `BrainContext` and both reply checks.
- **The check.** `scripts/dnb-ai-check.sh` with `scripts/lib/ai_check.php`, READ-ONLY. It reports which AI answers,
  the price list as the AI sees it, the knowledge rows and their cut parts, and recent real conversations with
  their replies, masked. `--ask` puts nine questions to the installed AI through the live path.
- **It reads a copy of the database, made as its owner.** The first draft opened the live file read-only, and the
  rehearsal showed SQLite leaving `-wal`/`-shm` behind, which would be root's on the server.
- **Rehearsal.** `scripts/harness/ai-check/rehearse.sh`, 63/63 twice. Eight weakened copies are caught. Canaries
  for every kind of personal or secret value stay out of the output.
- **Not changed.** No plugin release, no setting, no knowledge-base row, no uCRM record and no server change.
  Proposals P1–P7 (docs/40 §8) wait for the operator: the P1 wording (are both Residential plans unlimited?) and
  the P3 decision (should the AI quote the access point and MikroTik prices or keep handing over?).

## 5.18.44 — a business is sold the Residential plan; another area gets a design and a price (docs/40 §11)

**Why.** The check of 26 Sep ran on the server on 27 Sep (docs/40 §10). In the live conversations the assistant
framed every "business" as a Business plan, told a customer *"The plans we offer are not unlimited"*, told a
would-be reseller *"we don't have a reselling program"*, and never once priced the outdoor access point or the
MikroTik that were in uCRM — one customer who named the access point was asked to confirm its price. The MikroTik
was the seventh one-time item, and the price check refused any total with it. **The operator's decisions,
verbatim:** the data-allowance wording — *"keep as it is"*; covering another area — *"yes lets ai to desing and
give price of accespoint if avaible in system"*.

**What.** Only where the install runs the qualification and hardware modules (Uganda); South Sudan is
byte-identical (below).
- **Unlimited, stated.** Beside the plans, to be repeated word for word: *"Both Residential plans (Residential Lite
  and Residential) are unlimited, with no data cap. Only the Business plans come with a block of priority data
  (50 GB, 500 GB or 1 TB)."* `ai_fact_unlimited` replaces it (now in `set_config.php`); `omit` switches it off.
- **A business gets the Residential plans;** a Business plan is for a public IP, asked about once. Someone who wants
  to sell internet gets the higher-capacity Residential plan, and is never told there is no reseller programme.
- **NETWORK EQUIPMENT**, out of HARDWARE, each item named by what it is for (`assets/shop/network.json`), with the
  rule to design and price it: one access point unless the customer names a number, the survey confirming the
  rest; never a distance, area or user count; never inside a home total.
- **The price check** allows every combination of up to ten one-time items and 2 to 5 of an access point, on top
  of every total it allowed before. Only access points are multiplied.
- **The accessories reach the sales number and the website chat** (one rule, `BrainContext::catalogue`).
- **Approved knowledge up to 1,000 characters** (was 600). **MANY_USERS_HOTSPOT** said to hand over for a site
  assessment, the opposite of the decision; its seeded text now says to design and price, then book the survey.
- **Tools:** `seed_knowledge.php --dry-run` (rolled back) and `--only=KEY` (that row, no other).
- The check reads either version; `tests/conversation-suite.php` now asks the way the worker does (docs/40 P7).

**Found and fixed while building** (docs/40 §11.3–§11.4): the website chat would have shown every install the
accessories — caught by the South Sudan fingerprints; the first price-check draft multiplied every network item
and made every round 100,000 up to a million a legal total; the SELL INTERNET rule pointed at a design rule that
is absent where no equipment is listed. **Recorded, not changed:** the reply check also accepts an amount whose
digits occur anywhere in the prompt run together — pre-existing on both installs, **proposal P8 for approval**.

**Proof.** `tests/test_ai_unlimited_and_network.php` **159**, and **nineteen weakened copies each fail it**. South
Sudan: all 50 prompt fingerprints and the price-check list byte-identical to 5.18.43. No existing suite needed a
change. The check's rehearsal runs against 5.18.43 and 5.18.44: **183/183, twice**. Suite: **209 suites, exit 0, twice; the 188 suites that print totals report 8,426 passed / 0 failed on both runs.**

**Deploy.** `scripts/deploy-5.18.44.sh`, pinned to the plugin commit. After the documented deploy, stage K
corrects MANY_USERS_HOTSPOT — a dry run first, that row only, only while still as seeded, as the database's owner
— stage V re-checks everything 5.18.43 checked, and stage AI asks the assistant the ten questions and prints the
replies. **Rehearsed:** `scripts/harness/deploy-5.18.44/rehearse.sh` runs the pinned script against a sandbox
container. It gave **46/46 on three consecutive runs**, over seven scenarios, and five weakened copies each fail
(docs/40 §11.6). Its first run found two faults, both in the harness. One was a check for a line `--after-only`
never prints. The other was a weakened copy without `--only` that was masked by the sandbox's person-edited row:
the tool's *"Edited by hand"* stopped it before it wrote. That copy is now run on an estate with no person's
row, beside a control. **Not deployed by this session.**

**Deployed 27 Sep 2026 06:14 UTC by the operator: PASSED, 34 ok / 0 failed / 1 note** (docs/40 §13). The note was
the deploy script's own race and not the plugin's. `aihas` piped `printf` into `grep -q` under `pipefail`, and it
missed an access point that its own listing showed. Fixed in deploy-5.18.45.sh.

## 5.18.45 — more floors are Starlink routers; a Business priority block ends at about 1 Mbps (docs/40 §14)

**Why.** Two things came up on 27 Sep, once 5.18.44 was live.

- **Indoor coverage.** The operator: *"Ruijie Reyee RG-RAP6262(G) … this is out door and for indoor if some one
  want to cover more floor we have to suggest starlink routers"*.
- **Business data.** The live check showed the assistant telling a customer that a Business plan's priority block
  is followed by "unlimited standard data". That came from the BUSINESS_PLANS knowledge row. The note appended to
  Business replies says the opposite: about 1 Mbps until more is bought. Asked which was right, the operator
  answered: *"Drops to ~1 Mbps"*.

**What.** Uganda only: where the hardware module is on and a Starlink router is listed.

- **The Starlink routers.** Router Mini and Router 3 are taken from the shop catalogue's category *Router*, by exact
  name (`NetworkEquipment::starlinkRouters`). Nothing is guessed from a name: *Router 3 Mount* is a mount.
- **The prompt.** Both routers are marked in ACCESSORIES with what each fits. A new rule, **MORE FLOORS OR ROOMS
  INSIDE ONE BUILDING**:
  - Starlink routers as a mesh, never the outdoor access point or the MikroTik;
  - one router per floor beyond the main router's, as quantity × price;
  - the kit asked about in the same reply;
  - the survey confirms the number.

  NETWORK EQUIPMENT is marked *for outdoors*.
- **The price check** allows 1 to 5 of one router, with any of the kit and the installation. That is 160 more
  permitted totals, and not one round figure 5.18.44 refused.
- **BUSINESS_PLANS** now says *"the connection drops to about 1 Mbps until more is bought"*. It is 981 characters,
  and its short form 296, so both reach the assistant whole. Stage K corrects that row only.
- **The check tool.**
  - It lists the Starlink routers.
  - It says what each knowledge row claims happens after the priority block.
  - It asks eleven questions; the new one is about floors.
  - For a refused reply, it prints the **amounts it could not match and the draft**. Two replies were refused
    live on 27 Sep and the log could not say why.

**Recorded, not changed.**

- **P9:** the marker legend `<<ESCALATE reason>>` is sometimes copied word for word, so a hand-over's reason reads
  "reason". The legend is in South Sudan's prompt too, so this needs approval.
- **P8** is still open.

**Proof.**

- `tests/test_ai_indoor_routers.php` **84**; twelve weakened copies each fail it.
- South Sudan: all 50 fingerprints unchanged. Uganda with no Starlink router listed: 26 fingerprints byte-identical
  to 5.18.44 (a new golden from `a4abe5e`).
- The check's rehearsal against 5.18.44 and 5.18.45: **230/230, twice**.
- Suite: **210 suites, exit 0, twice; the 189 that print totals report 8,510 passed, 0 failed on both runs** (8,426 in 5.18.44, plus the new 84).

**Deploy.** `scripts/deploy-5.18.45.sh`, pinned to the plugin commit. Stage K corrects BUSINESS_PLANS, stage V as
5.18.44, and stage AI asks the eleven questions with the race-free matcher. Rehearsed in
`scripts/harness/deploy-5.18.45/rehearse.sh`: **53/53 on two consecutive runs**; six weakened copies each fail, including the matcher turned back into a pipe (the first run's one miss was the harness's detector, which still read "ten"). **Not deployed by this session.**

**First run — 27 Sep 2026, 07:00:32 UTC: NO-GO at the backup, nothing changed; 5.18.44 stays live.** The tar of
the plugin's data directory failed, and the script threw tar's words away. Most likely: *"file changed as we
read it"* on the live databases and logs.

The script now:
- copies `plugin.sqlite3` and `dishnet.sqlite` with `VACUUM INTO`, as their owner, integrity-checked, with the
  sha256 compared on both sides;
- archives the rest without them;
- treats tar exit 1 as a note with tar's words, and anything worse as a FAIL.

Same pin, same command. Rehearsal **108/108 on two consecutive runs**: the old script as the control, four real
failures, and seven weakened copies caught (docs/40 §15.1).

**Second run — 27 Sep 2026 07:54 UTC: PASSED, 39 ok / 0 failed / 0 notes** (docs/40 §16). The backup worked live:
`plugin.sqlite3` copied with `VACUUM INTO` as `1000:1000`, integrity ok, the same sha256 on both sides. There is no
`dishnet.sqlite` on this install, and tar exited 0. BUSINESS_PLANS is corrected, and V and AI are all `ok`. Three
of the eleven replies should have been refused and were not: two wrong setup totals written without commas
(1993500 and 1999500 for 1,897,500), and "[Sum of setup costs]". That is 5.18.46.

## 5.18.46 — a quotation summary a customer can read; the price check reads amounts without commas (docs/41)

**Why.**
- **The quotation summary.** The operator, on order 000114: *"here Monthly give wrong in message"*. It said
  "Hardware" for a total that included the installation, and "Monthly" beside a Total that already included the
  first month. The split guessed from words in each line: whatever lacked `kit`, `router`, `cable`,
  `installation`… was "Monthly". So on a bigger-area quote the access points, connectors and consultancy
  (1,519,500) were "Monthly", and a plan named "Monthly" was hardware ("ont"). The operator chose **"Use the
  clearer message (Recommended)"**.
- **The price check.** Stage AI of 5.18.45 (docs/40 §16) saw three replies the check should have refused: two
  wrong totals without commas, and an unfilled template slot.

**What.** Uganda only; South Sudan unchanged (below).
- **The quotation summary** now says:
  `💰 One-time: UGX 2,399,000` / `💰 First month: UGX 249,000 (then UGX 249,000 per month)` / `🏷️ Total: …`.
  - The plan is the line spelled like one of uCRM's service plans (`PublicPriceFeed::planKey`, the rule the price
    feed and the assistant already use). Every other line is one-time.
  - "Then … per month" is uCRM's plan price, so a discounted first month still shows the real monthly price.
  - Three months up front reads "First 3 months".
  - Where uCRM cannot list its plans, there is no split, and the log says why.
- **The price check** (`ReplyPrivacyGuard`), where the hardware module is on:
  - it also reads plain amounts of five digits or more, alone or straight after a currency;
  - it refuses a reply with an unfilled slot such as `[total]`;
  - it leaves out serials, invoice numbers, links, dates, speeds and Markdown links.
  - A refused reply becomes the safe fallback and a hand-over, as before.
- **The check tool** judges with the same options, and says which checks judged.

**South Sudan:** the quotation summary is byte-identical, with goldens taken from `webhook.php` at `fa2d463`, and
its uCRM is asked nothing more. With the module off, every price-check verdict is identical.

**Recorded, not changed.**
- **P10:** print the prompt's prices with commas, and give the assistant the totals. It changes the Uganda prompt,
  so it needs approval.
- **P8** and **P9** are still open.

**Proof.**
- `tests/test_quote_summary.php` **39**; six weakened copies of `webhook.php` each fail it.
- `tests/test_price_check_plain.php` **68**; eight weakened copies each fail it.
- The check's rehearsal against 5.18.45 and 5.18.46: **252/252**.
- Suite: **212 test files, exit 0, twice**.

**Deploy.** `scripts/deploy-5.18.46.sh`, pinned to `131712a`. It has the backup as fixed on 27 Sep, and stage V as
before. There is no stage K. Q checks that the installed webhook carries the new summary. Stage AI checks the new
price-check line, and reports a refused reply as a note, never a failure. Rehearsed in
`scripts/harness/deploy-5.18.46/rehearse.sh`: **117/117 on two consecutive runs**. Seven weakened copies of the new
checks and seven of the backup each fail. **Not deployed by this session.**

**Deployed 27 Sep 2026 08:59 UTC by the operator: PASSED, 40 ok / 0 failed / 0 notes** (docs/41 §8). The backup,
V, Q and AI were all `ok`. Two A1 replies should have been refused and were not:
- "Total: UGX 4,627,000", where the lines add up to 4,527,000. It equals a real sum of listed prices, the check's known
  limit: about 4 in 10 round amounts between 1 and 6 million are permitted totals.
- "(Add total of …)", a slot in round brackets.

**Proposal P11, for approval:** a stated total must add up to its lines, and a "TOTAL" with no figure is refused.

## 5.18.47 — the price check adds each total up (docs/41 §9)

**Why.** Stage AI of the 5.18.46 deploy (docs/41 §8) saw two A1 replies the check should have refused:
- "Total: UGX 4,627,000", where the lines add up to 4,527,000. The figure is itself a sum of listed prices, so the
  price-list rule could not tell (F-1).
- "TOTAL TO GET CONNECTED: (Add total of …)", a slot in round brackets.

The operator chose **"Build the total check (Recommended)"**.

**What.** Uganda only, switched by the hardware module; South Sudan unchanged.
- **`lib/ReplyTotals.php`** refuses a reply when:
  - its stated total is not the sum of the list lines above it — with or without the monthly lines, plus an earlier
    subtotal — nor a total it already stated;
  - a sum it writes out is wrong;
  - a money TOTAL carries no figure.

  The categories are `total:mismatch` and `total:missing`. A refused reply becomes the fallback and a hand-over, as
  for every refusal.
- **A reply is refused only if no reading of it makes the total right.** A count not beside its price ("2 × Router 3
  — 827,000", "Router Mini x2 — 435,000") is read both ways, as the price for one or for all; "each" says for one.
  Left to the price-list rule: two prices on a line, a price "each" with no count, a total with no list above it, a
  total in prose, and a label that is not money.
- **`ReplyPrivacyGuard::optionsFor`** gains `totals`. The check tool says so, and explains a refused total.

**Proof.**
- `tests/test_price_check_totals.php` **111**.
  - The 30 live replies of 27 Sep: exactly 5 refused (the 3 already refused, and the two A1 replies) and 25 sent as
    written.
  - Eighteen weakened copies each fail it.
- `tests/test_price_check_plain.php` **68**. Its pinned options now include the totals rule, deliberately.
- The check's rehearsal against 5.18.46 and 5.18.47: **273/273, twice**. Its first run found one false refusal —
  a count mid-line, "For the two upper floors: 2 × Router Mini — 435,000 each = 870,000". That, and two shapes like
  it, were fixed before anything shipped.
- Suite: **213 test files, exit 0, twice**.

**Deploy.** `scripts/deploy-5.18.47.sh`, pinned to `a9b46fb`. It is deploy-5.18.46.sh with one new line in stage AI;
the note that counts refused replies now also names a total that does not add up. Rehearsed in
`scripts/harness/deploy-5.18.47/rehearse.sh`: **125/125 on two consecutive runs**. Eight weakened copies of the checks
and seven of the backup each fail. **Not deployed by this session.**

## 5.18.48 — a Starlink kit price says what it includes (docs/42)

**Why.** On 27 Sep the live assistant quoted the Starlink Standard Kit at 2,649,000 with nothing about tax. The
operator asked for kits to say clearly that all taxes are included, URA taxes and the UCC registration fee among
them, and chose **"All taxes included (Recommended)"** and, for the quotation PDF, **"Yes, match it
(Recommended)"**.

**What.** Uganda only, switched by the hardware module; South Sudan unchanged.
- **`lib/KitTaxNote.php`.** After a reply passes the price check, a reply that states the price of a Starlink kit
  ends with: *"The kit price includes all taxes — URA taxes and the UCC registration fee are already in it. Nothing
  is added on top."*
  - A kit is a HARDWARE item whose name holds "Starlink" and "Kit". Accessories are never read, so the Travel Kit
    case never counts.
  - Nothing is added to a refused reply, to a reply that already says it, or to one with no kit price.
  - Added by code, as the Business-plan note is: that rule, stated in the prompt, was ignored by 18 of 21 replies.
- **`ai_fact_kit_taxes`.** Unset means the approved wording, `omit` switches it off, and any other text is used as
  written. `set_config.php` manages it and warns when the text holds a digit.
- **Quotation template, clause 2.** On a quote with a Starlink kit and no tax lines, "No VAT is charged on this
  quotation." becomes the same sentence. Quotes with tax lines, or with no kit, are unchanged. The template lives
  inside uCRM, so staff load it there (docs/42 §4): `template-quotation-uganda-2026-09-27.zip`, sha256
  `2a01039d…7994de3`, both entries byte-identical to the repository.
- **The check tool** reports the line in force and counts the replies that carried it.
- **Carries 5.18.47** (the total check, above), which was never deployed on its own. The 5.18.47 command now stops
  at stage A and changes nothing.

**Proof.**
- `tests/test_kit_tax_note.php` **73**, including eleven weakened copies. Through the real worker:
  - the live reply of 27 Sep passes the price check and carries the sentence;
  - South Sudan sends it exactly as written;
  - a refused reply gets the fallback and no line.
- The quotation template, rendered with real Twig 3 and 2.16 under the `2bfad82` template's own sandbox: **27/27 on
  each**. The rest of every page is byte-identical to the baseline's. Three weakened copies each fail.
- The check's rehearsal against 5.18.46 and 5.18.48: **284/284, twice**.
- Suite: **214 test files, exit 0, twice**.

**Deploy.** `scripts/deploy-5.18.48.sh`, pinned to `65b1ace`. Stage AI gains the line's report (ok, or a note when it
is your own wording or switched off; a failure with the hardware module off) and the count of replies that carried
it. Rehearsed in `scripts/harness/deploy-5.18.48/rehearse.sh`: **146/146 on two consecutive runs**. Its first run
caught the summary claiming the sentence while the line was switched off; the summary now states what the report
found, and a weakened copy that quotes it regardless is caught. **Not deployed by this session.**

**Deployed 27 Sep 2026 11:49 UTC by the operator: PASSED, 43 ok / 0 failed / 2 notes** (docs/42 §8). 5.18.47 went
live with it. Stage AI saw both:
- C1, a home quote with the Mini Kit, ends with the taxes line;
- B1 wrote "TOTAL: 1,996,500" over lines that add up to 1,897,500, and was refused — the customer would have
  had a person, not a total 99,000 too high.

The notes: tar read a changing log file (the databases are copied separately), and that one refusal.
**The quotation template is not yet loaded in uCRM** (docs/42 §4).

## 5.18.49 — every Uganda quotation says what its prices include (docs/42 §9)

**Why.** 5.18.48 left two points open (docs/42 §7). The operator answered on 27 Sep: *"we are giving quote including
all the taxes"*, and for the WhatsApp quotation summary, *"add it we are providing quote including UCC and URA
charges"*. They then chose **"One sentence (Recommended)"** for every quotation, and **"Yes, same sentence
(Recommended)"** for the assistant's price fact:

> All prices include all taxes — URA taxes and UCC charges are already in them. Nothing is added on top.

**What.** Uganda only, by the tenant profile; South Sudan byte-identical.
- **`lib/QuoteTaxLine.php`** holds the sentence once.
- **The WhatsApp quotation summary** carries it on the line under the Total, from both builders: a quote made in uCRM
  (`webhook.php`, `quote.add`) and a quote from the app or KYC (`QuotationService`).
- **Quotation template, clause 2.** Every quote with no tax lines says the sentence, with a kit or without. It
  replaces "No VAT is charged on this quotation" and 5.18.48's kit sentence; quotes with tax lines are unchanged. The
  template lives inside uCRM, so staff load it there: **`template-quotation-uganda-all-taxes.zip`**, sha256
  `16acf0b1…f502e400`, both entries byte-identical to the repository. **The 27 Sep ZIP of 5.18.48 is superseded and
  must not be uploaded.**
- **The price fact** (`ai_fact_prices`) is not set by the deploy. The operator's own command sets it
  (docs/42 §10.1); `set_config.php`'s help now shows the sentence.
- **The check tool** reports the price fact, and counts a kit quote that already says the taxes are included, so a
  log never reads "no kit price quoted" about one that did. Its wording for an unset fact was corrected before
  shipping: the prompt's TAX rule does tell the assistant something.

**Proof.**
- `tests/test_quote_tax_line.php` **31**, new, including five weakened copies. `QuotationService` for South Sudan is
  byte-identical to its `4c01d1c` copy; Uganda's gains exactly one line.
- `tests/test_quote_summary.php` **45**: section K through the real webhook, and two more weakened copies.
- `test_kit_tax_note.php` (68) and `test_airtel_money.php` (58) changed on purpose, each saying why.
- The quotation template with real Twig 3 and 2.16: **27/27 on each**, three controls failing as they should.
- The check's rehearsal against 5.18.48 and 5.18.49: **325/325, twice**.
- Suite: **215 files, exit 0, twice**.

**Deploy.** `scripts/deploy-5.18.49.sh`, pinned to `e076632`. Stage Q reads the three installed files; stage AI
reports the price fact (ok when it is the quotations' sentence, a note otherwise); the summary names the new ZIP.
Rehearsed in `scripts/harness/deploy-5.18.49/rehearse.sh`: **187/187 on two consecutive runs**. **Not deployed by this session.**

**Deployed 27 Sep 2026 12:54 UTC by the operator: PASSED, 47 ok / 0 failed / 1 note** (docs/42 §11). The price fact
was set first, by the operator's own command, and stage AI read it as the quotations' sentence. Stage Q found the
sentence in all three installed files. The note: B1 wrote a total 199,000 over its lines and was refused. C1, a home
quote with the Mini Kit, ends with the kit line. Seen, not changed: B1's second reply still defers tax to the
quotation (P12, proposed for approval), and B4 quoted three routers for three floors.
**The quotation template is not yet loaded in uCRM** (docs/42 §10.2).

## 27 Sep — staff job assignment, uCRM and WhatsApp: a read-only audit (docs/43)

**What.** An audit, at the operator's request, of how a job reaches a technician or retailer and how they answer.
**No plugin change.** Nothing was deployed, no job created, no record changed and no message sent.

**Found.**
- Jobs are uCRM Scheduling jobs. My Jobs → ＋ New Job assigns and messages support staff; the time lands 3 hours
  late in uCRM.
- Hard-coded South Sudan lists overwrite each staff member's uCRM user id on every deploy and page load.
- A job made in uCRM reaches the technician only if uCRM's user record carries a phone; the fallback is broken.
  Reassignment sends nothing, and B + C send duplicates.
- A technician's WhatsApp reply goes to the AI as a customer message.
- Any signed-in account can read, close or complete any job.
- A failed message can show as "Delivered".
- "0/5 CRM linked" counts the organisation-7 reseller link, not the job link.

The smallest set of changes is J1–J8 (required) and J9–J16 (optional), in docs/43 §9. **None is authorised yet.**

**New read-only tools, no plugin change:**
- `scripts/dnb-jobs-facts.sh` with `scripts/lib/jobs_facts.php`, for the operator to run: 277/277 on two runs in
  `scripts/harness/jobs-facts/rehearse.sh`, seven weakened copies caught.
- `scripts/harness/jobs-trace/trace.php`, the sandbox trace behind the audit: 46/46 on two runs.

**Next.** The operator runs the facts command and sends back its log file (docs/43 §10.4), then chooses which
changes to build. The controlled live test (§10.5) waits for that approval.

**Facts measured 27 Sep 15:54 UTC by the operator's run** (docs/43 §11):
- Uganda's uCRM has **one staff user, id 1000, with the admin account's e-mail**, and its record has **no phone
  field**.
- None of the plugin's four stored ids (1, 4, 81, 1581) is a uCRM user; 4 was typed after the screenshot.
- Organisation 7 does not exist.
- There are **0 scheduling jobs**, and no job message or job webhook has ever existed.
- 6 staff conversations are filed as customer ones, and the AI has been queued **109** times for staff numbers.
- The admin alert number is unset. The timezone is Africa/Kampala.

Consequences:
- Nobody can be assigned a uCRM job correctly today.
- Path B can never send.
- Each technician needs a uCRM user, created by the operator.

The facts command's "tenant profile not set" line described the setting, not the profile: the currency UGX selects
uganda. It now prints the resolved profile and why. Its rehearsal: **334/334 on two runs**, eight weakened copies
caught.

## 27 Sep — J1–J8: the implementation specification (docs/44), and a read-only uCRM users check

**What.** The operator accepted docs/43 and approved J1–J8 **for planning only**. It asked for one specification
before any code, and for one more read-only check of Uganda's uCRM users. **No plugin change, nothing deployed, no
record changed, no message sent.** J9–J16 stay out of scope.

**docs/44** answers the operator's ten questions. It gives:
- per change: the files and functions (5.18.49 line numbers), today vs wanted, schema, tests and rollback;
- one Uganda switch, `StaffJobsGate`, false on any error, so South Sudan stays byte-identical;
- **one schema change**: migration 075, `job_notify_state` + `job_notify_events` for J4 ("who was last told what",
  claimed in one transaction, so each change sends exactly one message, whichever path sees it);
- the test matrix, and two releases: A sends nothing new; B, J4, comes after the operator creates and links the
  uCRM users;
- **eight decisions for the operator** (D1–D8). Two notable ones: telling a technician when a job is taken away
  (recommended), and removing the "Notify via WhatsApp" box on Uganda.

**Delivery receipts** are specified as "not measured" rather than captured. The shape of Evolution's
`messages.update` has never been recorded here.

**New read-only tool, no plugin change:** `scripts/dnb-ucrm-users-facts.sh` with `scripts/lib/ucrm_users_facts.php`.
It reads:
- every uCRM user's list AND detail record (phone-like fields, nested ones included, as names and country codes
  only);
- what `users/{id}` answers for a real id;
- the e-mail match each staff account would get;
- the two other uCRM-id settings;
- uCRM's timestamp offset;
- the follow-up switch;
- each WhatsApp number's subscribed webhook events.

Rehearsed in `scripts/harness/ucrm-users/rehearse.sh`: **394/394 on two runs**, 11 weakened copies caught, and the
mask proved with a control on the control.

**Next.** The operator runs the users check and sends back its log file (docs/44 §13), and answers D1–D8. **Nothing
is coded until the operator approves docs/44.**

**Result of the uCRM users check, 27 Sep 16:37 UTC** (docs/44 §13.1). It ran to its end: 3 ok, 0 failed, 5
notes; nothing was changed. Measured:
- Uganda's uCRM has **one staff user, 1000**, active and UISP-linked, with the admin account's e-mail. There is
  **no phone field** in its list or detail record.
- **`users/{id}` answers 404 even for 1000**, so path B fails at its first step.
- A verified picker would propose **S1 → 1000**. S2–S5 have no uCRM user, and **0 of 4** stored ids are real.
- **uCRM writes +0300**: its screen keeps Kampala's clock.
- **The follow-up engine is on**, so J8's follow-up exclusion is needed.
- **Delivery receipts are subscribed on all three WhatsApp numbers**; the plugin drops them today.

The specification stands as written. Nothing is coded until the operator approves docs/44 and answers D1–D8.

**Second run of the uCRM users check, 27 Sep 16:47 UTC** (docs/44 §13.1), after the operator created a UISP user
for S4. Measured:
- uCRM now has two staff users: 1000 (S1's e-mail) and **1099 (S4's e-mail)**. Both are active and UISP-linked,
  and neither has a phone.
- A verified picker would propose **S1 → 1000** and **S4 → 1099**. S4 still holds 81 until it is linked.
- Nothing was changed.

**Decisions and readiness, 27 Sep** (docs/44 §15), asked for before any approval. Documentation only; nothing
changed on the server.
- **P1 first**, as the operator's advisor asked: who will be assigned jobs. S4 has uCRM user 1099 and S1 has 1000;
  S3 and S5 are undecided.
- **D9 (new):** link only to the uCRM user with the same e-mail. As written, J2 let an admin pick another user, and
  UISP holds no names for these users.
- **M1:** J8 must also stop follow-ups by the number, at the scan, the run and the send. As written, the category
  is set only at a staff member's next message, and a follow-up opened earlier would continue.
- **M2:** a message-content test (T4.13). **M3:** a title-only edit in the live test must send nothing.
- **M4 (optional):** one silent internal test job after the links, to prove V2–V4 before release B can send.
- **M5:** clear S3's and S5's stale ids unless they take jobs. 1581 is above uCRM's user numbers so far, so it
  could one day name a real user.
- Readiness: release A can be built once P1, D1, D2, D6, D7, D9, M1 and M5 are answered; nothing may send until
  release B.

## 27 Sep — J1–J8: the operator's decisions recorded; release A awaits M6 and M7; release B BLOCKED

**What.** The operator reviewed docs/44 §15 and approved the two-release approach. Recorded in docs/44 §15.6.
**No code written, nothing deployed, no record changed, no message sent.**

**Decisions.**
- **P1:**
  - S1 yes, for the internal test and administration, with a valid number before any WhatsApp test;
  - S4 yes, after release A and a verified link to 1099;
  - **S3 and S5 no for now**; S2 and retailers no.
  - No account is created and no job assigned to anyone else automatically.
- **D1–D4, D6–D9: yes.** D8 is the operator's own settings command, in release A's runbook.
- **D5 pending:** the exact messages (new assignment, reassignment, new time, removal, deletion) come to the operator
  before release B is built.
- **M1–M5 approved.** M4 runs after release A, assigned to S1, with no job message sent. M5 clears only S3's and S5's
  ids; no other link is cleared or overwritten automatically.

**Requirements recorded** (docs/44 §15.6): the full suite twice with zero failures, South Sudan regression included;
a verified backup and the deploy and rollback commands; no automatic deploy; no silent link change; live-test
messages to the operator's own number only; no change to customer billing, invoices, the portal or unrelated
features. **A passing automated test is not proof that WhatsApp delivers**: release B's live test is its own gate.

**Still blocking release A** (docs/44 §15.7): two confirmations.
- **M6:** as specified, release A would still let the old code send a job-assignment WhatsApp. ＋ New Job sends with
  its box ticked, Bulk Dispatch always, and Reschedule to whoever pressed it; uCRM's `job.add` is stopped only by a
  404. Recommended: switch all four off on Uganda in release A and hide the box.
- **M7:** recommended: on Uganda only a link saved through the verified picker counts, wherever a job is involved, so
  S3's and S5's old ids match nobody even before M5 clears them.

**Correction to docs/44 §3.2**, inside the approved J2 ("never use `ftth_crm_client_id`"): the My Jobs list reads it
as a fallback (`api_scheduling.php:70, 73, 84, 141`). Release A removes the fallback on Uganda.

**For requirement 8:** J1's change 1.8 sits beside the retailer wallet top-up's uCRM invoice attempt, which fails
today (no organisation 7). The outcome is unchanged, so it is kept as approved unless the operator says otherwise.

**Release B: BLOCKED** until all of these hold (docs/44 §15.8), and then only on separate approval:
- D5 approved;
- release A deployed and verified;
- the links verified (S1 → 1000, S4 → 1099) and S3's and S5's ids cleared;
- S1 has a valid number;
- M4 done with no job message sent;
- the tests pass twice.

## 27 Sep — J1–J8: M6 and M7 approved; strict billing exclusion; release A being built, not deployed

**What.** The operator approved **M6** (release A sends no job-assignment WhatsApp on Uganda: ＋ New Job, Bulk
Dispatch, Reschedule and uCRM's `job.add` off; the checkbox hidden; "no message was sent" stated) and **M7** (on
Uganda only a picker-validated link is used for job operations; never `ftth_crm_client_id`, never an old id). The J2
correction is accepted. Recorded in docs/44 §15.9.

**Strict exclusion:** billing, invoices, payments, customer records and their workflows are not modified.
Consequences for release A:
- **Out:** 1.8–1.10 (organisation-7 client creation, the wallet top-up path, its message) and 5.3 (the second-site
  KYC job's time).
- **Narrowed:** J3's save rule applies to job-taking roles only; J8 recognises staff roles only, not dealer accounts.
- **Reported, not changed:** `update_client_gps` skips its job check for an unlinked account; `save_job_signature`
  logs to a customer named in the request body; the app API's job check-in and check-out have no assignee check.

**Next.** Build release A (5.18.50), run the full suite twice with zero failures, rehearse the deployment and the
rollback, and stop before deployment for the operator's review of the build report. Release B stays BLOCKED.

## 5.18.50 — Release A of J1–J8: staff and jobs follow Uganda's rules (docs/44 §16)

**Why.** The operator approved release A on 27 Sep with M6 (no job-assignment WhatsApp on Uganda), M7 (only a
picker-verified uCRM link counts for jobs), the J2 correction and a strict exclusion of billing, invoices, payments,
customer records and their workflows (docs/44 §15.9). Release A was to be built, tested twice, its deployment and
rollback rehearsed, and **not deployed** until the build report is reviewed.

**What.** Uganda only, behind one switch (`lib/StaffJobsGate.php`); South Sudan byte-identical. Plugin commit
**`125fa0c`**: 38 files against 5.18.49 (`e076632`), 20 changed and 18 added.
- **J1** the South Sudan staff lists never touch a Uganda account. **J2 + D9 + M7** the checked uCRM picker; only a
  link saved through it counts for My Jobs, job detail, every job action, New Job and Bulk Dispatch — never
  `ftth_crm_client_id`, never an old id (so S3's and S5's stale ids match nobody). **J3** job-taking accounts' phones
  in the international form. **J5** Kampala time to uCRM from New Job, Bulk Dispatch and Reschedule. **J6 + D7** only
  the verified assignee, a support leader or an admin acts on a job; the caller re-read on every action.
- **M6** New Job, Bulk Dispatch, Reschedule and uCRM's `job.add` send no WhatsApp and say so; the box is hidden.
  Accept, task-progress, completion and invoice-request messages unchanged.
- **J7** "sent", never "delivered"; suppressed and skipped counted. **J8 + M1** a staff number is not answered by
  the AI as a customer, and no follow-up is opened or sent for it.
- **Not changed:** billing, invoices, payments, customer records; 1.8–1.10 and 5.3 left out as instructed.

**Proof.**
- Full suite, twice, on `125fa0c`: **223 files, 10,422 assertions passed, 0 failed, exit 0 — both runs**.
- Eight new suites, **393 assertions, 59 weakened copies, all caught**; the South Sudan golden compares a whole
  working day on 5.18.49 and on 5.18.50 — every answer, message, uCRM request and seven whole pages.
- The first full run failed 8 assertions in two suites, both defects of the new test code (a zone named in a
  fixture, which `test_timezone.php` forbids; the version label on every page after the bump). Fixed in the tests;
  no product line changed.
- The 37 changed PHP files use nothing newer than PHP 8.0 (a scan with a control of 7 planted features).

**Deploy.** `scripts/deploy-5.18.50.sh`, pinned to `125fa0c`, only over `e076632`: backup first, a typed `DEPLOY`,
NO-GO unless the installed plugin reads Uganda from both configuration sources and the server's own PHP accepts every
changed file; afterwards every changed file, the switch, the staff accounts, the Message Log and the screens are
checked. `--rollback` puts `e076632` back the same way. Rehearsed in `scripts/harness/deploy-5.18.50/rehearse.sh` —
real `deploy-hybrid.sh` and real `git checkout` in a clone: **99/99 on two consecutive runs**, 12 weakened copies of the script each caught. Evidence in `docs/evidence/5.18.50/`.
**Not deployed by this session.** Release B stays BLOCKED; the five D5 messages are in docs/44 §16.7
for approval.

**Deployed 27 Sep 2026 20:08 UTC by the operator: PASSED, 41 ok / 0 failed / 1 note** (docs/44 §16.9).
- The server's PHP is **8.1.34**, and it accepted every changed file. The installed plugin reads Uganda from both
  configuration sources, and every changed file is installed exactly as `125fa0c` has it.
- No staff account changed. No Message Log row of any kind was written from the mark (#374) to 20:12.
- The note: none of the 4 accounts that take jobs holds a verified link yet. My Jobs is empty for all four until
  their links are saved.
- **Two rollback runs followed**, at 20:09 and 20:12, because the chat handover put the deploy and rollback commands
  in one copyable block. Both stopped at the typed question and changed nothing. From now on a rollback command
  always stands in a block of its own.

**20:31 UTC — the links** (docs/44 §16.10). S1 → 1000 and S4 → 1099 saved through the picker, both verified. M5 (S3's
and S5's old ids) and S1's number are still to do. The users check's follow-up line described 5.18.49 ("a staff
member's included"). It now reads the installed crons: 413/413 on two runs, 14 weakened copies caught.

**20:42 UTC — `--after-only`: 32 ok, 1 failed, 2 notes** (docs/44 §16.11). R4 still 0 and no Message Log row since
#374. V4 found 4 fatal lines, all `cron/master.php:83`: the master closes its lock at its normal end, and its shutdown
handler then unlocks the closed handle, which is fatal on PHP 8.
- **Not Release A's:** `master.php` and `main.php` are unchanged since 13 Sep, and Release A changes no `exit`.
  **Measured:** it has fired 4–9 times an hour, every hour, since at least 26 Sep 00:00 UTC — 5.18.49 included.
- The work of each run is done and saved before it.
- A one-line fix (`is_resource()`) is proposed as 5.18.51, awaiting approval.

**Release B stays BLOCKED.**

## 27 Sep — release B redesigned the South Sudan way: D5 approved; 5.18.52 being built, not deployed (docs/44 §16.12)

The operator tested a job assignment in South Sudan's system, where their number is also a staff number, and asked
for Uganda to work the same way. The proposal put to them was answered **"approved"**, at about 21:25 UTC.
- **Message 1**, on assignment and on reassignment to you. It carries an **ACCEPT JOB** link to the staff app's job
  page, which is behind the staff sign-in; after signing in, the engineer lands on that job.
- **Message 2**, after Accept, carries the completion link. It replaces the engineer's "Job Accepted"; the leaders'
  message is unchanged.
- D3's notices take the same greeting and footer. There is no Re Assign for engineers.
- *"This is DishNet Africa."*, and Kampala time.

South Sudan's page is on another host, addressed by the bare job number. Whether it opens without signing in is
unverified and was not probed.

**Release B is 5.18.52**, because 5.18.51 is the lock fix. "approved" was taken to cover building now. The deploy
still waits for M5, S1's number, M4 and a separate approval.

**The WA Events badge has no defect after all.** Its test fails between 21:00 and 24:00 UTC. That was first read, in
the chat, as the webhook writing UTC. Measured instead: uCRM's events reach the webhook only through `public.php`, which
applies Kampala time first, so the log and the badge share one clock (docs/44 §16.12). The test's fake log used the test
process's own clock. 5.18.52 corrects the test, not the badge.

## 5.18.51 — the master cron releases its lock once (docs/44 §16.13)

- **The change, plugin commit `240f2f9`.** `cron/master.php`'s shutdown handler releases the lock only while the handle
  is still open. 5.18.50 released it a second time, after the normal end had closed it. On PHP 8 that is a fatal
  `TypeError`: 4–9 times an hour on the server, and every shutdown function registered after the handler was skipped.
- **`tests/test_master_lock_release.php`, 27 assertions.** It runs `master.php`'s own lock code in a child process. The
  control reproduces the server's exact fatal line with 5.18.50's handler, and three weakened guards are each caught.
- **The suite, twice on `240f2f9`:** 224 files, 10,445 passed, 2 failed, identical file by file. The 2 are the badge
  test's clock between 21:00 and 24:00 UTC (docs/44 §16.12).
- **`scripts/deploy-5.18.51.sh` (`1517dc8`).** It installs `240f2f9` by its hash, also after later pushes. Rehearsed
  131/131 twice; 23 weakened copies caught. The rollback is a separate command.
- **Not deployed by this session.**

**Deployed 28 Sep 2026 04:26 UTC by the operator: PASSED, 44 ok / 0 failed / 2 notes** (docs/44 §16.15).
- The pin held: the branch stood at `fc5c3b7` (5.18.52), and the script installed `240f2f9` by its hash and put the
  checkout back on the branch.
- The server's PHP 8.1.34 accepted the changed PHP files. Every changed file is installed as `240f2f9` has it, and no
  staff account and no Message Log row changed.
- **Note R8:** the lock line is judged by `--after-only` an hour after the deploy.
- **Found, not yet explained:**
  - **The verified links read 0 of 4**, where 2 were verified at 20:42 UTC the night before (R7). The users check will
    say why.
  - **The hour before the deploy held no line of the master's lock error**, where every hour of 26–27 Sep held at
    least 4. `--after-only` reads the master's own record of its runs and will say whether it is running.

**07:09 UTC — `--after-only`: PASSED, 37 ok / 0 failed / 3 notes, and the users check** (docs/44 §16.17).
- **R8:** no lock-error line in the 142 minutes after 04:46, while 39 of the master's jobs ran. The fix holds and the
  master runs.
- **The links are back:** S1 → 1000 and S4 → 1099 verified again, saved after 04:26. S3 and S5 still hold 4 and 1581
  (M5 to do).

## 5.18.52 — release B: job messages the South Sudan way; built and rehearsed, not deployed (docs/44 §16.14)

- **The change, plugin commit `fc5c3b7`, Uganda only.** One component, `lib/JobNotifier.php`, sends every job WhatsApp
  message, once per change, to the engineer uCRM has on the job. It finds that engineer through a verified link only
  (M7). The messages:
  - message 1, with an ACCEPT JOB link;
  - after Accept, message 2 with the completion link, in place of the engineer's "Job Accepted";
  - a new time; "no longer assigned"; "cancelled".
- **The link survives the staff sign-in.** Jobs made in My Jobs and Bulk Dispatch are created Open, so the Accept
  button shows.
- **Migration 075** adds the notifier's two tables. South Sudan is byte-identical, apart from those two empty tables.
- **Tests.** Two new suites (107 and 97 assertions), the day scenario rewritten against 5.18.51 (46), and the badge
  test's clock corrected (27, no failure). Weakened copies caught: 16 by the notifier suite, 6 by the day scenario and
  one more by the badge test.
- **The suite, twice on `fc5c3b7`:** 226 files, 10,656 passed, 0 failed, identical file by file. Both runs fell inside
  the 21:00–24:00 UTC window in which 5.18.51's badge test failed; it passes there now.
- **`scripts/deploy-5.18.52.sh` (`9e1740d`).** It goes over 5.18.51 only, and installs `fc5c3b7` by its hash.
  Rehearsed 159/159 twice; 28 weakened copies caught. The rollback is a separate command, printed on its own.
- **Not deployed.** It waits for 5.18.51, then M5, S1's number and M4, then a separate approval.

## 28 Sep — 5.18.52 also e-mails the engineer each job message (docs/44 §16.16)

- **Asked for.** The operator answered the offer with *"i need"*: the same text as each job WhatsApp, to the e-mail on
  the engineer's staff account. 5.18.52 was not deployed, so the change is part of it. **5.18.52 is now plugin commit
  `7ad465e`**; `fc5c3b7` was never deployed.
- **The change, Uganda only.** Every message the notifier sends also goes by e-mail, to the same verified staff account
  (M7):
  - message 1, message 2, a new time, "no longer assigned" and "cancelled";
  - through the plugin's mail server, with the tenant's reply address;
  - the text part is the WhatsApp text, byte for byte, under a subject that names the job;
  - it goes whether or not the WhatsApp did.
- **Its outcome is recorded beside the WhatsApp's:** sent, failed, no usable address, or no mail server. It appears in
  the history, the log line and the screens' note. After the mail server refuses, the rest of the request tries no more
  e-mail.
- **Migration 076** adds the two history columns. South Sudan is unchanged.
- **Tests only:** the sandbox no longer runs the admin page's piggyback cron, which reached `wa.dishnetafrica.com` and
  `dishnetss.com` from the tests.
- **Tests.**
  - `test_job_messages.php` 132 (was 107); `test_job_notifier.php` 130 (was 97), with 22 weakened copies caught;
    `test_job_notifications_day.php` 50 (was 46), with 7.
  - Under PHP 8.1.34 (php-wasm): lint clean, 132 of 132, and the notifier's output byte-identical to PHP 8.4's.
- **The suite, twice on `7ad465e`:** 226 files, 10,718 passed, 0 failed, identical file by file. Only the three job
  suites differ from `fc5c3b7`'s runs.
- **`scripts/deploy-5.18.52.sh` (`e5d3264`).** It installs `7ad465e` by its hash. R9 also checks migration 076. R11,
  new, says which mail server the e-mail will use and prints no value. Rehearsed 170/170 twice; 30 weakened copies
  caught. The rollback is a separate command.
- **Not deployed.** The order stands: 5.18.51's `--after-only`, the users check, M5, S1's number and M4, then a
  separate approval.

## 28 Sep — the jobs facts report counts `job.edit` and `job.delete` (docs/44 §16.17)

- **The defect.** `scripts/lib/jobs_facts.php` looked for the event's name in quotes inside a log line. No line the
  webhook writes has that form: it is in the webhook's HTTP answer, which is not logged. A delivered `job.edit` or
  `job.delete` would have read 0, and M4's evidence (V2) would have failed on a false reading.
- **The fix.** Each delivery is counted once, by the webhook's *"Received UCRM webhook: …"* line. The job.add handler's
  lines are counted apart, 5.18.51's *"not switched on yet"* included. docs/43's reading stands: uCRM held no job then.
- **Rehearsed** 391/391 twice, with a seed of the lines 5.18.51 really writes. 10 weakened copies caught (2 new).
  Read-only, as before; no plugin file changed.

**07:26 UTC — the corrected report on the server** (docs/44 §16.18). uCRM delivers every job event to the plugin: job.add
7, job.edit 1, job.delete 5 since 25 Sep (V2), and a job's detail carries its assignee (V4). No job message was sent. Of
M4 only V3 is left (the hour of a job made in ＋ New Job). M5 and S1's number are still to do.

**About 07:35 UTC — "approve 5.18.52"** (docs/44 §16.19). The deploy command was handed over on its own; its rollback
is printed only at the end of the log. M5, S1's number and V3 remain; none blocks the deploy itself. The rehearsal on
the branch tip read 170/170 again.

**Deployed 28 Sep 2026 07:41 UTC by the operator: PASSED, 44 ok / 0 failed / 3 notes** (docs/44 §16.20). `7ad465e` is
live; migrations 075 and 076 applied; the engineer's e-mail goes through the plugin's own SMTP settings. The first job
on 5.18.52, #8, was created at about 07:43 UTC, assigned to S4. Every test job made with a customer also sent that
customer the older "installation booked" e-mail; test jobs need no customer.

## 28 Sep — job #8 left no record; a read-only check and a test job (docs/44 §16.21)

- **The 07:55 UTC `--after-only`: PASSED, 37 ok / 0 failed / 3 notes.** But job #8 left no trace: `job_notify_state`
  and `job_notify_events` are empty, and the Message Log still ends at #380. The operator created job #8 at about 07:43
  UTC in uCRM, assigned to S4 (verified link), with the test customer.
- **The job.add handler ran to its end,** because the customer's e-mail went, and the notifier wrote nothing. Either
  the Uganda gate read "not Uganda" in that request, or the notifier stopped before its claim; its log line names the
  reason.
- **Handed over:** a read-only check that prints one job's lines from the plugin's webhook log, masked. It was tested
  on a sample log under PHP 8.4.19 and 8.1.34, with identical output. Also handed over: the test the operator asked
  for, a ＋ New Job with the test customer and S4, whose note shows the notifier's result.
- **Nothing changed.**

## 28 Sep — job #8 ran on 5.18.51's code after 5.18.52 was installed (docs/44 §16.22)

- **The check's answer:** job #8's job.add, at 07:43:30 UTC, logged *"WhatsApp skipped: job notifications are not
  switched on yet"*. Only 5.18.50 and 5.18.51 write that line. The web server ran an older compiled copy about 95
  seconds after 5.18.52's files were in place.
- **Likely PHP's opcode cache** (php-fpm's OPcache); its settings are not yet measured. No check of the deploy could
  see it, because every "serves" check reads files, not what PHP runs.
- **Next, read-only:** job #9's lines (the operator's next test job, 08:26 UTC), php-fpm's start time and its OPcache
  settings. A php-fpm reload, if needed, is its own approval.

## 28 Sep — job #9 ran on 5.18.51's code too; PHP's code cache (docs/44 §16.23)

- **Job #9,** created in uCRM at 08:26 UTC with the test customer and S4, logged 5.18.51's *"not switched on yet"* 45
  minutes after the deploy. php-fpm has run since 15 Sep. Its OPcache re-checks files every 2 s
  (`validate_timestamps = 1`, `revalidate_freq = 2`).
- **The explanation that fits:** OPcache compares a file's modification time in whole seconds. Five PHP files may
  carry the same second as the 5.18.51 copies, because of how the 5.18.51 deploy's checkout moves wrote them.
  Reproduced locally; to be confirmed on the server.
- **Handed over:** `check.sh` (read-only), and `fix.sh` (a new timestamp for the 20 files, content checked against
  `7ad465e` and unchanged, no restart), only if the check marks `webhook.php`. Then the test: change job #9's time in
  uCRM. Evidence in `docs/evidence/5.18.52/opcache/`.
- **Proposed, not built:** copied files get the copy time, and every deploy checks the code PHP runs.

## 28 Sep — the cause confirmed and fixed: the same second, 04:26:40 (docs/44 §16.24)

- **The check:** five PHP files the web server runs had the modification second of the 5.18.51 copies, 04:26:40. They
  are `webhook.php`, `public.php`, `api_scheduling.php`, `post_auth.php` and `bulk_dispatch.php`. `git reflog` shows
  the 5.18.51 deploy's checkout of `240f2f9` and its return to the branch in that one second.
- **So from 07:41 to 09:18 UTC the web server ran 5.18.51's copies of those five files.**
- **The fix, by the operator at 09:18:55 UTC:** 20 files given a new timestamp. Each was checked against `7ad465e`
  first; none differed, and their content is unchanged. The check then showed 0 files with the old second. Nothing was
  restarted.
- **Still to see:** the new code running. Job #9's time is to be changed in uCRM, then the job-log check run.

## 28 Sep — a walk-through script: one test job, each step one at a time (docs/44 §16.25)

- **Asked for** by the operator: *"prepare script which can create new job and all the steps … one by one"*.
- **`scripts/job-walkthrough.sh`** creates one test job in uCRM, for the test customer and uCRM user #1099. It takes
  the job through six steps: create, new time, the technician's Accept, taken away, given back, deleted. It asks
  before each, and after each prints what the plugin did and a verdict.
- **Safe by construction:**
  - a read-only preflight (NO-GO creates nothing);
  - it changes only the job carrying its own run mark;
  - it stops on 5.18.51's code, and offers to delete the job;
  - one run at a time; masked output and its own log file.
- **Unassigning and deleting a job** are uCRM API calls the plugin has never made; they are measured only against the
  fake. A refusal is printed and the run goes on.
- **Rehearsed** against the plugin's sandbox with a webhook-sending fake uCRM, in `scripts/harness/job-walkthrough/`.
  Two runs, each 36 of 36, and six weakened copies caught. `php -l` is clean under PHP 8.1.34.
- **Not run on the server.**

## 28 Sep — the walk-through's first run: job #10 (docs/44 §16.26)

- **Steps 1, 2 and 6 as expected:** message 1, the new time and the cancellation, each by WhatsApp and by e-mail, and
  the customer's e-mail. 5.18.52 runs, and deleting a job through uCRM's API works.
- **Step 4:** uCRM refuses a time with nobody assigned (422, measured on job #10). So step 5's *"nothing to send"* was
  right: the engineer had never left the job.
- **Step 3, open:** uCRM had the job In progress, but no Accept was recorded. Only DishNet's Accept sends message 2,
  and it claims the job first, so the plugin's record tells which: `--facts 10`, read-only.
- **F-WT1, found, not changed:** *"no longer assigned"* will read *"Date: Not scheduled yet"*, because uCRM takes the
  time away with the engineer. A plugin change, for its own approval.
- **The script:**
  - step 4 takes the time away too;
  - step 5 comes only after step 4 did, and sets the job Open again;
  - step 3 reads the claim and offers one more try in DishNet;
  - a new read-only `--facts N`.

  Rehearsed twice, identically: 77 of 77 assertions, and eleven weakened copies caught.

## 28 Sep — job #10's Accept stopped after its claim; an Accept test (docs/44 §16.27)

- **`--facts 10`:** DishNet's Accept claimed job #10 for S4, but wrote no Message Log row and no history row for
  message 2. It stopped between its claim and those two records.
- **Why that is possible:** the staff app's API ends a request at any PHP warning (public.php:891); the webhook only
  logs one. Both records also swallow database errors.
- **Reproduced in the sandbox:** one PHP warning in message 2's text leaves exactly job #10's trail.
- **Five explanations,** told apart by what reached S4 (nothing, the WhatsApp only, the e-mail only, or both) and by
  what the job page showed. Asked of the operator.
- **A new `--accept-test`** runs DishNet's Accept as the staff app does, and writes down every PHP warning, exception
  and fatal error. Beside them it prints the error level and the ini file that sets it, how long the Accept took,
  PHP-FPM's user, and the WhatsApp transport each settings copy gives. It sends real messages to S4. `scripts/` only;
  the plugin is unchanged.
- **Proposed for 5.18.53, not built:** the Accept survives a warning; the history row first; the job page shows what
  became of message 2.
- Rehearsed twice, identically: 107 assertions, and seventeen weakened copies caught.

## 28–30 Sep — message 2 goes, its records are lost: the cause and a one-line fix (docs/44 §16.28)

- **The Accept test on the server (job #11):** no PHP warning, no error, 2.0 s, Evolution in both settings copies.
  The Accept code says message 2 was **sent**, yet neither its history row nor its Message Log row exists. So the
  warning explanations are ruled out.
- **The cause:**
  - the Accept's claim leaves its database read open (`lib/JobNotifier.php:192`);
  - uCRM's notice of the Accept's own status change makes the webhook write the job's record meanwhile;
  - the Accept's later writes then fail at once with *"database is locked"*, and each of them swallows the error.
  Measured with two connections, then reproduced with the real plugin code, both through the Accept test and through
  the staff app's own API.
- **The fix, shown in the sandbox:** `$st->closeCursor();` after the claim's read. Both rows are saved.
- **So on 28 September S4 most likely received message 2 for jobs #10 and #11.** Asked of the operator.
- **Proposed for 5.18.53, not built:**
  - the fix in `accepted()` and `observe()`;
  - a log line whenever a record cannot be written;
  - a regression test that fails without the fix.
- **The Accept test** now names this case, and shows when another process wrote the job's record during the Accept.
  `scripts/` only; the plugin is unchanged.
- Rehearsed twice, identically: 121 assertions over twenty-two scenarios, and nineteen weakened copies
  caught.

## 5.18.53 — message 2's records saved, and a line whenever a record cannot be; built and rehearsed, not deployed (docs/44 §16.29)

- **The change, plugin commit `6b71ea6`,** on the operator's approval of §16.28's items 1 to 3:
  - **the fix:** the job notifier's claim ends its read before its COMMIT, in `accepted()` and in `observe()`. Two
    lines, `$st->closeCursor();`;
  - **the log:** when the Message Log row, the failure-queue row, the Inbox row, the echo claim or the job history row
    cannot be saved, one line goes to the plugin log (`data/plugin.log`, the log uCRM shows on the plugin's page): the
    record, its table, the event or job, and the error. Never a number, an address or a text. The send is never
    undone;
  - **the test:** `test_job_records_race.php` (46) forces 28 September's race through the real staff API and webhook.
    With the fix every record is saved; 5.18.52 loses them and says nothing; with the fix taken out, four lines say
    which were lost. Twelve weakened copies are each caught.
- **Not in it:** F-WT1 and `whatsapp_note` on the job page (not approved).
- **South Sudan:** nothing sent, shown or stored changes. A record that cannot be saved now has its line there too.
- **PHP 8.1.34 (the server's):** every changed PHP file passes `php -l`, and the helper and the open read behave as
  under 8.4.19.
- **The suite, twice on `6b71ea6`:** 227 files, 10,764 passed, 0 failed, identical file by file. Against 5.18.52 only
  the new file differs.
- **`scripts/deploy-5.18.53.sh` (`0045253`).** It goes over 5.18.52 only, and installs `6b71ea6` by its hash.
  New: each changed file gets the time of the copy (§16.23), and R12 checks those times and OPcache's settings; R13
  reads the plugin log's *"not saved"* lines since the deploy. Rehearsed 198/198 twice; 36 weakened copies caught.
  The rollback is a separate command, printed on its own.
- **Not deployed.** Next: the deploy command, then the Accept test (§16.27).

## 30 Sep — 5.18.53 deployed (PASSED 49/0/2), and the Accept test on it (docs/44 §16.30)

- **The deploy, 06:33 UTC:** `6b71ea6` over `7ad465e`, installed as the checkout stood at `edc0085`. 49 ok, 0 failed;
  notes R7 (2 of the 4 job-taking accounts verified, M5) and R11 (the plugin's SMTP). The 8 changed files carry the
  deploy's time and OPcache re-checks times (R12); there is no plugin log yet (R13). Backup
  `/root/dnb-5.18.53/backup-20260930T063323Z`.
- **The Accept test, job #13, 06:35 UTC:** message 2 went by WhatsApp and e-mail in 1.7 s, and **its history row and
  Message Log row were saved, while the webhook wrote the job's record during the Accept**: the collision that lost
  both on 28 September. Message 1 and *"cancelled"* went and were recorded too.
- **Next:** `--after-only` after a day of real jobs.

## 30 Sep — notifications and bulk communication, uCRM and the plugin: an audit (docs/45)

**What.** An audit, at the operator's request, of every notification uCRM and the plugin send on Uganda, and of
uCRM's bulk ("multi-user") communication. **Nothing was changed:** no code, configuration, schedule or record;
nothing deployed; nothing sent.

**Found.**
- The plugin carries almost all customer communication: WhatsApp for every event, and the eight Uganda e-mails
  switched on 15 Sep.
- uCRM's own notifications, its mailer, its e-mail log and its bulk e-mail ("Mailing") have **never been looked at on
  this server**. Its documentation is blocked from this session, so every uCRM feature is marked unverified.
- Possible duplicates with uCRM: invoice, quotation, receipt, suspension. KYC quotes always trigger uCRM's quote
  e-mail.
- Reminders go by two paths with separate guards. The overdue WhatsApp still says *"suspending tonight"*, although
  the prepaid e-mail ladder was stopped for saying so.
- Ten code defects (docs/45 §4.4). Among them: the payment webhook stops after the receipt (run here under PHP 8.1.34,
  the server's version); the retailer app can send a second receipt; the WhatsApp "Event Map" switches are read by no
  sender.
- **No usable way to message many customers at once** about maintenance or news. The plugin's outage alert has no
  screen, and uCRM's Mailing is unverified.
- Seven staff-side findings (docs/45 §4.5). Among them: the failure-queue API checks no role, and the 07:00
  staff-jobs brief never sends.
- Missing: e-mail for customers without a phone, and for invoices raised as drafts; retries and delivery receipts;
  SMS; consent and unsubscribe; admin and watchdog alert numbers.

**Next.** The read-only checks V1–V11 (docs/45 §8), then the owner decisions O1–O8. Every fix (F1–F14) and gap
(G1–G11) waits for approval, and none is part of Release A or B.

## 30 Sep — 5.18.54: notification reliability, built and rehearsed, not deployed (docs/46)

**What.** The bug-fix project that followed docs/45: every customer notification once, through the right channel, at
the right time, with a record and a safe retry. Repository only: **nothing deployed, no setting changed, no message
sent, no uCRM notification switched on or off.** Every change is behind `NotifyGate`, true only on Uganda; South Sudan
runs 5.18.53's code.

**Built** (docs/46 §A, §B): 47 rows, each with its tests and weakened copies. Among them:
- the payment webhook carries on after the receipt, and a payment gets one WhatsApp receipt (rows 1–4);
- reminders come from one daily run in the daytime, each tier once, with the prepaid rules (5–8, 20);
- the failure-queue API is for administrators only, and no GET link sends or changes uCRM (10, 11);
- one message per uCRM event, credit note and quote; numbers in international form (12–17, 38, 45);
- a refused WhatsApp is retried a bounded number of times; one that may have gone never is (30, 31, 42, 44);
- a watchdog for stopped jobs and piling failures, and System Health reads master's record (32, 43).

**Not built, with the reason** (docs/46 §D): e-mail retries and delivery receipts, SMS, consent for marketing, the
broadcast screen, and four senders outside the Message Log (N-20), among 16. **Decisions for you** (§E): 12, none
needed to review the work. uCRM's own e-mails stay on (E-12).

**Tested:** the full plugin suite twice on `e8a8508`: 247 files, 11,336 assertions, 0 failed, identical file by
file; the South Sudan comparison green in both. **Rehearsed:** the deploy script (`fde675c`) twice: 227 checks each,
0 failed, 94 runs of the script, 41 weakened copies caught. Evidence: `docs/evidence/5.18.54/`.

**Next.** Your review of docs/46 §G–§H, then, on your approval only, `scripts/deploy-5.18.54.sh` (its rollback is
printed at the end of its log), then the checks of §G.

## 30 Sep — distribution management on uCRM and the plugin: an audit and a phased plan (docs/47)

**What.** An audit of the uCRM installation and the DishNet plugin, and a phased plan for a distribution network of
prospective partners: fuel-station chains, supermarkets, shops, distributors and wholesale buyers. **Audit only:**
- no code, migration, setting or uCRM record was changed;
- nothing was deployed;
- no server was contacted;
- no test was run.

**Found:**
- **uCRM** (4.5.33, measured 23 Sep) can hold a partner as one **company client**: invoices, payments, credit notes,
  balance. It has no place for an outlet, and no partner price list.
- **uCRM organisations** are DishNet's own issuing entities, and Uganda has one. They must not be used for partners.
- **The plugin already has most of the machinery:**
  - stock units and movements, purchases and costing, and the authoritative kit binding (B-1);
  - the append-only financial audit and per-currency reporting;
  - the job scheduler, country gating and the customer-portal session design.
- **Missing:** partners, outlets, the owner of stock apart from its location, dispatch and receipt, consignment,
  dated prices and commission rules, settlements and partner sign-in.
- **Partner users cannot safely sign in through anything that exists today.** The plan is a minimal, separate
  partner portal with its own identity and a deny-by-default API.
- **Tax is the one real block.** uCRM has no VAT configured, and the plugin refuses EFRIS production. Invoicing a
  VAT-registered partner waits on the accountant.
- **Existing defects** (docs/47 §7.2, PD-1 to PD-13):
  - **PD-1:** the collections CSV export appears to have no sign-in check. It was found by reading and **not
    reproduced**, and a fix of its own is recommended now.
  - Duplicate serials are possible.
  - Stock balances are clamped at zero.
  - Migrations are marked applied after a failed statement.
  - A payment turns a residential client into a company client.

**Proposed** (docs/47 §14):
- Phase 0 foundations first.
- Then partners and outlets; the stock journal; prices, orders and sales; commissions and settlements.
- Then a **staff-operated pilot before any partner signs in**, then the partner portal, then the rollout.
- Every phase is additive, switched off at deploy, tested with weakened copies, and deployed by a pinned script with
  a separate rollback.

**Next.** Your review:
- the decisions D-1 to D-15 (D-6 needs the accountant);
- the verifications NV-1 to NV-13, three of which write a test record to uCRM and need your approval;
- PD-1 on its own.

Nothing is built until you approve a phase.

## 30 Sep — security & financial-integrity verification (docs/48); PD-1 collections-export fix (5.18.55)

The master security + financial-integrity review, and the first authorised fix.
**Nothing deployed.** The live server still runs 5.18.53.

**Verified (read-only; docs/48 is the full report):**
- The notification defects (docs/45) are fixed in the 5.18.54 code and are
  test-pinned, but 5.18.54 is **not deployed** — so live 5.18.53 still exhibits
  them. Handling is a deploy decision.
- The security findings **PD-1..PD-13** (docs/47 §7.2) and the stock / migration /
  financial-integrity findings are **confirmed in code** and present in **both**
  5.18.53 and 5.18.54.

**Fixed in code (5.18.55):**
- **PD-1 — the collections CSV export had no sign-in or role check.** It runs at
  public.php:739, before the page login gate at public.php:1027, keyed only on
  `tab=all_collections&col_export=csv` — an anonymous dump of every customer's
  payment collection (11 columns incl. customer name, uCRM client id, amount,
  method, uCRM payment id). Added `$auth->requireLogin()` + an admin gate as the
  first statements of the export block (includes/routes.php), before any data is
  read, mirroring the staff-cashbook export. All Collections is an admin-only tab.
- New regression test `tests/test_collections_export_auth.php` (8 assertions),
  with a control-on-control: it fails if the gate is removed.

**Tests (`bash tests/run.sh`):** before = 247 files / 11,295 ok / 0 failed / exit 0;
the new test passes 8/8 standalone; the full suite is re-run twice after the
change as the acceptance confirmation.

**Held for approval (nothing done):**
- Deploy of any release — including 5.18.54 to fix **D-1** (the payment-webhook
  crash, already fixed in 5.18.54, no new code needed).
- The other remediation batches (PD-2..PD-13; FI1..FI5; S1..S5; U1/U2) — docs/48 §7;
  several are blocked pending an accounting or business decision.
- The distribution module (docs/47) — waits on this review being approved.

No production/config/data change; no customer or staff message; no payment or
refund; no accounting or tax decision; nothing deployed.

## 30 Sep — 5.18.55 deploy APPROVED; pinned deploy script + rehearsal ready (awaiting the operator's run)

The operator approved deploying 5.18.55. **Scope, stated plainly:** the live server
runs 5.18.53; 5.18.54 was built but never deployed; so deploying 5.18.55 takes live
**5.18.53 → 5.18.55** in one step — landing the ENTIRE 5.18.54 notification-reliability
release (docs/46: D-1 the payment-webhook fix, receipts-once, one reminder path,
prepaid wording, S-1 failure-queue roles, the watchdog, the staff brief, …) PLUS
**PD-1** (the collections-export sign-in/admin gate, docs/48). This one deploy
therefore also resolves the outstanding D-1 deploy question.

**Prepared and validated (nothing deployed):**
- `scripts/deploy-5.18.55.sh` — pinned to 9514633, over baseline 5.18.53 (6b71ea6),
  backup-first documented deploy, full R-stage verification (R1 byte-checks every
  changed file vs the pinned commit; R1b names the PD-1 gate), a SEPARATE typed
  ROLLBACK to 5.18.53. Derived from the rehearsed deploy-5.18.54.sh; deploy/rollback
  machinery byte-identical.
- `scripts/harness/deploy-5.18.55/rehearse.sh` — rehearsal **228 passed, 0 failed**
  (deploy PASSED, rollback PASSED, forward-again, auto-rollback on a failed page
  check, branch-ahead control at 5.18.56, OPcache-timing §16.23, all weakened copies
  caught).
- Full suite `bash tests/run.sh`: green **before** (247 files / 11,295 ok / 0 failed)
  and **twice after** (248 / 11,303 / 0, twice).

**Handed to the operator** to run as root on the server (cd /opt/dishnet, pull the
branch, run the script, tee to a log file). The rollback command is given separately
and is never pasted together with the deploy (docs/44 §16.9). The result will be
recorded here once the operator sends the log file. No production change has been
made from this session.

## 30 Sep — 5.18.55 DEPLOYED to production, 20:07:46 UTC (PASSED 55/0/3)

The operator ran `scripts/deploy-5.18.55.sh` on the server (dishnetuganda, /opt/dishnet).
**Result: `5.18.55 (deploy): PASSED` — 55 ok, 0 failed, 3 notes.** Live moved
5.18.53 (6b71ea6) → 5.18.55 (9514633); container PHP 8.1.34. Backup-first under
`/root/dnb-5.18.55/backup-20260930T200746Z` (plugin.sqlite3 24M, integrity ok,
228 tables, sha256 match; data dirs; installed 5.18.53 code; config vault). The
release changes no table or row — a rollback needs no restore.

Verified live:
- R1: all 93 changed files installed byte-for-byte as 9514633; manifest 5.18.55.
- **R1 PD-1: the collections CSV export now requires sign-in + admin before any data
  is read** — the docs/48 §5 security fix is confirmed in production.
- R2: NotifyGate reads Uganda from both config sources; all 23 of 5.18.54's fixes on
  — including **D-1 (payment-webhook), receipts-once, one daily reminder path,
  prepaid wording, S-1 failure-queue roles, the watchdog and the staff brief.**
- V1–V4: public sign-in 200, no redirect loop, no :8443 leak; Uganda Terms/Privacy
  v1.1 (no South Sudan literal); no fatal/parse error since the deploy.
- R3 staff unchanged; R12 changed files carry the deploy's time (OPcache recompiles).

Three notes (configuration, not errors):
- R7: 2 of 4 active job-taking staff have a verified uCRM link; the other 2 receive
  no job WhatsApps until an admin saves their uCRM user (Staff → edit → uCRM user).
- R14: `whatsapp_admin_phone` is unset, so the notification watchdog's alerts reach
  only the plugin log, not WhatsApp (docs/46 E-11).
- R11: the engineer's job e-mail goes through the plugin's own SMTP settings.

Rollback to 5.18.53 remains available on its own (docs/44 §16.9):
`cd /opt/dishnet && bash scripts/deploy-5.18.55.sh --rollback`.

**docs/48: D-1 (§3) and PD-1 (§5) are now resolved in production.** The remaining
docs/48 items (PD-2..PD-13, FI1..FI5, S1..S5, U1) stay staged for a later decision.

## 30 Sep — 5.18.56 BUILT (Staff Cashbooks `$config` scope fix); deploy script + rehearsal ready; NOT deployed

The operator reported a `Warning: Undefined variable $config` on every figure card of the
**Staff Cashbooks** screen (admin/accountant only) and asked to check it. Root cause and fix:

- `tabs/accounts/staff_cashbooks.php:846` defines the money formatter
  `function scM(float $n):string{...dn_cur($config)...}`. A PHP function does **not** inherit
  the including scope, so the bare `$config` was undefined inside `scM()` — hence the warning on
  every card that calls it. The **amounts were already correct** (`dn_cur()` defaults to `UGX`
  when config is absent); only the warning text leaked into the UI.
- **Fix (one line):** `scM()` now brings `$config` into scope with `global $config`, matching the
  codebase's existing pattern (`tabs/customer_app/portal_data.php:1092`). Verified by tracing the
  include chain: the tab is required at global scope (`public.php:3008`, inside two `if` blocks,
  no enclosing function), so `global $config` binds to the same `$config` (`public.php:494`) the
  rest of the tab uses — the formatter now shows the configured symbol **and** emits no warning.
- **Pre-existing, not from 5.18.55:** `git diff 6b71ea6 9514633 -- <file>` is empty — this file
  was not touched by the 5.18.55 release; the bug has been latent on an admin-only screen.

**Tested.** New regression test `tests/test_staff_cashbook_scope.php` (9 assertions): structural
(scM declares `$config` global) + runtime (scM called with no `$config` in scope raises no
"Undefined variable" warning and still renders `UGX`) + a control on the control (the pre-fix
definition **does** warn). `php -l` clean. **Full plugin suite green** (`tests/run.sh` exit 0;
249 test files, `php "$t" || fail=1` then `exit "$fail"` — 0 means every file passed). No existing
test conflicts (only `test_cashbook_currency.php` reads the file's source, and its assertions do
not match the added line; no test pins the manifest version).

**Committed** to `claude/study-this-jhe2eg` at **`81d4324`** (manifest bumped 5.18.55 → 5.18.56).

**Deploy prepared (operator-run; this session cannot reach production).**
`scripts/deploy-5.18.56.sh` — pinned to `81d4324`, **baseline 5.18.55 (`9514633`)**, rollback to
5.18.55. It is a diff-proven minimal derivation of the production-run `deploy-5.18.55.sh`: only the
pin/version/baseline labels, the output paths, two RELEASE_A labels, the new **R1c** line (names
the installed scM fix), the rollback note and two summary lines change — the backup /
GO-NO-GO / typed-DEPLOY / typed-ROLLBACK / R1-R14 machinery is byte-identical. With baseline
5.18.55, stages **R2-R14 double as a full regression check** that 5.18.52-5.18.55 are intact
(NotifyGate's fixes on, the job notifier wired, migrations 075/076, **PD-1's export gate, R1b**).
Rehearsed in `scripts/harness/deploy-5.18.56/rehearse.sh` against a fake container starting at
5.18.55: **68 passed, 0 failed, twice** — the deploy PASSES, R1c fires (with a control: a missing
scM fix on the server is caught, and an R1c-blinded copy of the script is detected), the 3-file
delta installs byte-for-byte, the rollback returns `staff_cashbooks.php` to 5.18.55.

Run on the server as root, then send back **the log file** (rollback is a separate command,
printed at the end of the deploy's log — never pasted together with the deploy, docs/44 §16.9):

    cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
      && mkdir -p /root/dnb-5.18.56 \
      && bash scripts/deploy-5.18.56.sh 2>&1 | tee /root/dnb-5.18.56/deploy-$(date -u +%Y%m%dT%H%M%SZ).log

**Also (operator action, unrelated to the code):** set `whatsapp_admin_phone` to `211927797217`
in **Engage → WhatsApp** ("🔔 Admin Alert Number") — this resolves the R14 note from the 5.18.55
deploy (the admin number was unset, so the watchdog/KYC/handover alerts reached only the plugin
log). `+211` is the South Sudan code, consistent with that screen's existing `211…` examples.

## 30 Sep — 5.18.56 DEPLOYED to production, ~21:16 UTC (PASSED 56/0/3)

The operator ran `scripts/deploy-5.18.56.sh` on the server (dishnetuganda, /opt/dishnet).
**Result: `5.18.56 (deploy): PASSED` — 56 ok, 0 failed, 3 notes.** Live moved
5.18.55 (9514633) → 5.18.56 (81d4324); container PHP 8.1.34. Backup-first under
`/root/dnb-5.18.56/backup-20260930T211513Z` (plugin.sqlite3 24M, integrity ok, 228 tables,
sha256 match both sides; data dirs; installed 5.18.55 code; config vault). **The release
changes no table or row — a rollback needs no restore.**

Verified live:
- **R1 5.18.56: the Staff Cashbooks scM() money formatter brings `$config` into scope — no
  "Undefined variable $config" warning on its figure cards.** The docs/48-adjacent display fix
  is confirmed in production; the admin/accountant screen is clean.
- R1: all 3 changed files installed byte-for-byte as 81d4324; manifest 5.18.56; the 131 other
  files of Release A through 5.18.55 installed exactly as pinned (full regression).
- **Regression intact:** R1b PD-1 collections-export gate present; R2 NotifyGate all 23 of
  5.18.54's fixes on (store 23/23, files 23/23); R5/R6 the job notifier wired; R9 migrations
  075/076 applied (4 jobs / 10 events); R8 the 5.18.51 lock guard.
- R3 staff unchanged (digest 0c5c170623a6dc3d, 5 accounts); V1–V4 sign-in 200, no redirect
  loop, no :8443 leak, Uganda Terms/Privacy v1.1, no fatal/parse error since the deploy;
  R12 the 3 changed files carry the deploy's time (OPcache recompiles at next use).

Three notes (configuration, not errors), carried over from the 5.18.55 state:
- R7: 2 of 4 active job-taking staff have a verified uCRM link; the other 2 receive no job
  WhatsApps until an admin saves their uCRM user (Staff → edit → uCRM user).
- **R14: `whatsapp_admin_phone` is still unset (store `empty`, files `unset`), so the
  notification watchdog's alerts reach only the plugin log (docs/46 E-11).** The requested
  `211927797217` has NOT yet been entered — operator action in Engage → WhatsApp.
- R11: the engineer's job e-mail goes through the plugin's own SMTP settings.

Rollback to 5.18.55 remains available on its own (docs/44 §16.9):
`cd /opt/dishnet && bash scripts/deploy-5.18.56.sh --rollback` (reintroduces only the cosmetic
scM warning; all of 5.18.54's fixes and PD-1 stay in place).

## 30 Sep — 5.18.57 BUILT (Uganda accounting UI shows UGX, not South Sudan's SSP); deploy script + rehearsal ready; NOT deployed

**Reported by the operator (screenshot of the Staff Cashbooks screen):** the accountant/admin
cash screens showed *"💵 USD Cashbook 🇸🇸 SSP Cashbook"* on the **Uganda (UGX)** install. SSP is
South Sudan's local secondary cash currency; it must never appear on Uganda. A proper account-
manager UI audit of the accounting plane found the leak across seven screens.

**Root cause (measured, not inferred):** the cashbook was built for South Sudan's dual-currency
(USD + SSP) model and the SSP layer was hardcoded. The plugin already carried the tenant helpers
(`dn_ssp_selectable($config)` → false on Uganda; `dn_book_base($config)` → UGX on Uganda, USD on
Sudan) but the accounting screens never consulted them. The "USD" cashbook tab is really the BASE
bag — on Uganda it already holds UGX money (collections write the base currency), so relabelling it
UGX is correct, **not** a data change.

**Fixed in code (5.18.57) — display-only, tenant-gated:**
- `tabs/accounts/staff_cashbooks.php` — derives `$scSSP = dn_ssp_selectable($config)` and
  `$scBaseCode = dn_book_base($config)` once; the base tab reads *"💵 UGX Cashbook"*; the
  🇸🇸 SSP tab, the SSP bag column, the USD↔SSP exchange modal + Convert button, the SSP
  grid/stat/footer sub-items and the "SSP Received" category are all wrapped in `if($scSSP)`;
  the manual-entry currency dropdown loops `dn_book_currencies($config)`.
- `tabs/accounts/ssp_imprest.php`, `ssp_cashbook.php` — whole-screen early return on Uganda
  (mirrors `ssp_overview.php`): *"SSP flows are not enabled on this installation…"*.
- `public.php` — the Cashbook nav label is tenant-aware; the SSP Imprest nav item is hidden on
  Uganda (`'roles'=>[]`).
- `tabs/accounts/cash_declaration.php` — the three SSP cash-count blocks gated; base labels from
  `dn_book_base`.
- `tabs/accounts/cash_advances.php`, `fiber_costs.php` — the SSP dropdown option gated.
- **On South Sudan every gate stays open and `$scBaseCode = 'USD'`, so the screens render
  byte-for-byte as before.** No amount, stored row, ledger or accounting logic changes.

**Proof:** new `tests/test_cashbook_tenant.php` (**26/0**) RENDERS the real tab-bar and dropdown
fragments under a Uganda config and under a South Sudan config and reads the output as an account
manager would: Uganda shows *"UGX Cashbook"*, no *"SSP Cashbook"*, no 🇸🇸; South Sudan unchanged.
It carries a **control on the control** — strip the `$scSSP` gate and the SSP tab reappears on
Uganda. Full plugin suite green (0 FAIL). `php -l` clean on all seven screens.

**Committed** to `claude/study-this-jhe2eg` at **`eea3d65`** (manifest bumped 5.18.56 → 5.18.57).

`scripts/deploy-5.18.57.sh` — pinned to `eea3d65`, **baseline 5.18.56 (`81d4324`)**, rollback to
5.18.56. It is a diff-proven minimal derivation of the production-run `deploy-5.18.56.sh`: only the
pin/version/baseline labels, the output paths, the RELEASE_A labels, the new **R1d** check, the
rollback note and two summary lines change — the backup / GO-NO-GO / typed-DEPLOY / typed-ROLLBACK /
R1-R14 machinery is byte-identical. **R1d** names the tenant gate on the installed screens; **R1c**
(the inherited scM `$config` fix) and stages R2-R14 double as a full regression check that
5.18.52-5.18.56 are intact.

Rehearsed in `scripts/harness/deploy-5.18.57/rehearse.sh` against a fake container starting at
5.18.56: **70 passed, 0 failed, three times** — the deploy PASSES; R1d fires (with teeth: revert
the gate on the server and R1d fails, naming it); the 9-file delta installs byte-for-byte; the
rollback returns the accounting screens to 5.18.56 (SSP visible on Uganda again); the base gate
refuses an older commit; a weakened copy whose R1d grep can no longer tell the fix apart is caught.

**Deploy is HELD for the operator's explicit approval.** When approved, the pinned command (stands
alone; the rollback prints separately at the end of its log — docs/44 §16.9):

    cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
      && mkdir -p /root/dnb-5.18.57 \
      && bash scripts/deploy-5.18.57.sh 2>&1 | tee /root/dnb-5.18.57/deploy-$(date -u +%Y%m%dT%H%M%SZ).log

**Scope note:** this release fixes the **accounting plane** (seven screens). A follow-up **5.18.58**
is offered for the same SSP-on-Uganda pattern on the sales/support plane (`my_account.php`,
`wallet.php`, `field_expenses.php`) and the `fiber_costs.php:190` `$` USD-symbol leak. Still
outstanding from 5.18.56 (operator action, not code): **`whatsapp_admin_phone` = `211927797217`**
in Engage → WhatsApp.

## 1 Oct — 5.18.58 BUILT (Uganda sales/support cash screens show UGX, not SSP); deploy script + rehearsal ready; NOT deployed

**Part two of the tenant-currency fix** (5.18.57 cleared the accountant/admin accounting plane;
this clears the field-agent sales/support plane). Same approach: display-only, tenant-gated on
`dn_ssp_selectable($config)`. On **South Sudan** every gate stays open, `dn_book_base='USD'` and
`dn_cur='$'`, so the screens render **byte-for-byte** as before. No amount, stored row, ledger or
accounting logic changes.

**Fixed in code (5.18.58):**
- `tabs/support/field_expenses.php` — the balance hero gates its **"🇸🇸 SSP Balance"** tile on
  `dn_ssp_selectable` and labels the base tile from `dn_book_base` (Uganda → "💵 UGX Balance"); the
  grid collapses to one column. (The currency toggle below was already tenant-aware.)
- `tabs/sales/my_account.php` — `$_mcIsSupport` now requires `dn_ssp_selectable`, so the SSP-first
  support hero, the SSP cashbook buttons, the SSP position summary and the SSP ledger **collapse to
  the base-currency view** on Uganda; the field-accountant SSP hero tile is gated; the
  `ssp_book`/`usd_book` views redirect to summary on Uganda; the expense and advance currency
  toggles gate the SSP pill and label the base pill from `dn_book_base`.
- `tabs/sales/wallet.php` — `$fr_is_support_role` requires `dn_ssp_selectable` (the SSP-first
  field-register hero collapses to the base hero); the SSP currency filter button, the SSP summary
  section, the collection-role SSP card and the entry-modal SSP currency pill are gated; base
  labels/symbol follow `dn_book_base`/`dn_cur` (the entry-modal amount prefix via `rtrim(dn_cur)` so
  it stays `$` on Sudan).
- `tabs/accounts/fiber_costs.php` — `fc_fmt()` derives its symbol from `rtrim(dn_cur($config))`
  instead of a hardcoded `$` (byte-identical `$100.00` on Sudan; `UGX100.00` on Uganda).

**Proof:** new `tests/test_sales_support_tenant.php` (**36/0**) RENDERS the Field Expenses balance
hero and `fc_fmt()` under a Uganda config and a South Sudan config, asserts no SSP / no 🇸🇸 on
Uganda and the base currency shown, structural gate checks for all four screens, the support-flag
collapse, and a **control on the control** (strip the `$feSSP` gate → the SSP tile reappears on
Uganda). `php -l` clean on all four screens. The currency/cashbook/tenant test subset passes
(**369 assertions, 0 failed** across six tests incl. 5.18.57's `test_cashbook_tenant`). The full
plugin suite is **0 FAIL through every test that touches these files** (all alphabetically before
the slow `test_job_*` fake-server cluster); a full-suite run to completion is slow on this host
(fake-HTTP-server tests) and is the background regression net — the change is display-only and
isolated to four presentation files with no code path to the job/notification/WhatsApp tests.

**Committed** to `claude/study-this-jhe2eg` at **`fcab6bd`** (manifest 5.18.57 → 5.18.58).

`scripts/deploy-5.18.58.sh` — pinned to `fcab6bd`, **baseline 5.18.57 (`eea3d65`)**, rollback to
5.18.57. Diff-proven minimal derivation of the 5.18.57 deploy script: only the pin/labels, output
paths, the new **R1e** check, the rollback note and two summary lines change — the machinery is
byte-identical. **R1e** names the sales/support tenant gate on the installed screens; **R1c** (the
5.18.56 scM fix), **R1d** (the 5.18.57 accounting gate) and R2–R14 re-verify 5.18.52–5.18.57 are
intact.

Rehearsed in `scripts/harness/deploy-5.18.58/rehearse.sh` against a fake container starting at
5.18.57: **71 passed, 0 failed, twice** — the deploy PASSES; R1e has teeth (revert the gate on the
server → R1e fails, naming it); the 6-file delta installs byte-for-byte; the rollback returns the
field-agent screens to 5.18.57 (SSP visible on Uganda again); the base gate refuses an older commit;
a weakened copy whose R1e grep can no longer tell the fix apart is caught.

**Deploy is HELD for the operator's explicit approval, and chains after 5.18.57** (its base gate
refuses any live commit but 5.18.57's `eea3d65`). When 5.18.57 is live and 5.18.58 is approved, the
pinned command (stands alone; the rollback prints separately at the end of its log — docs/44 §16.9):

    cd /opt/dishnet && git pull origin claude/study-this-jhe2eg \
      && mkdir -p /root/dnb-5.18.58 \
      && bash scripts/deploy-5.18.58.sh 2>&1 | tee /root/dnb-5.18.58/deploy-$(date -u +%Y%m%dT%H%M%SZ).log

With 5.18.58 the Uganda SSP-on-UGX cleanup covers the **whole plugin UI** (accounting +
sales/support). Still outstanding (operator action, not code): no **`whatsapp_admin_phone`** is set
in either copy of the settings, so the watchdog's alerts reach only the plugin log (deploy note R14;
set a WhatsApp number in Engage → WhatsApp to also receive them on a phone).

### DEPLOYED — 2026-10-01 04:05 UTC — PASSED (58 ok, 0 failed, 4 notes)

Run by the operator on `dishnetuganda`:
`bash scripts/deploy-5.18.58.sh … | tee /root/dnb-5.18.58/deploy-20261001T040503Z.log`. **The chain
held:** stage A before-evidence read **live `eea3d65` / 5.18.57**, so 5.18.57 went in first and
5.18.58 deployed on top of it exactly as its base gate requires. Plugin commit `fcab6bd`, version
5.18.58; container PHP 8.1.34 accepted all four changed screens (A2).

- **Backup first** — `plugin.sqlite3` (24M, integrity ok, same sha256 both sides), the data dir
  (116M), the installed 5.18.57 tree (11M) and the config vault, under
  `/root/dnb-5.18.58/backup-20261001T040503Z`. (The one tar "file changed as we read it" note is a
  live log rotating during the copy — benign, archived as found.)
- **B** — `DEPLOY` typed; container now serves `fcab6bd`; the 6 changed files were stamped with the
  copy time so PHP-FPM recompiles each at next use.
- **V** — public sign-in **200, no loop, no `:8443` leak**; Terms/Privacy render Uganda-correct
  (A1/A2, version 1.1, no South Sudan literal); `:8443` redirects correctly; **no fatal/parse error**
  since the deploy.
- **R — the fix is confirmed installed and live:** R1 "5.18.58: the sales/support cash screens are
  tenant-aware — Field Expenses, My Account and Wallet hide SSP on Uganda, and fiber_costs' symbol
  follows the tenant"; all 6 files byte-match `fcab6bd`; the 138 other Release-A-through-5.18.57 files
  intact. The full **5.18.52–5.18.57 regression net is green** — PD-1 export gate (R1b), scM fix
  (R1c), **5.18.57 accounting tenant gate (R1d)**, NotifyGate 23/23 (R2), job notifier wired (R5,R6),
  migrations 075/076 applied (R9), lock guard (R8), scheduler can send (R14).
- **The data is untouched** — this release changes no table and no row, so a rollback needs no
  restore. Rollback to 5.18.57 remains `bash scripts/deploy-5.18.58.sh --rollback` (typed ROLLBACK),
  printed on its own at the end of the deploy log.
- **4 notes, all pre-existing / informational:** R7 — 2 of 4 active job-taking staff still lack a
  verified uCRM link (they get no job WhatsApp until an admin saves their uCRM user via the picker);
  R11 — engineer e-mail uses the plugin's SMTP; R14 — no `whatsapp_admin_phone` set (above); the tar
  live-log note.

**5.18.58 is live. The Uganda SSP-on-UGX UI cleanup is complete end to end; South Sudan is
unchanged.**

## 01 Oct — 5.18.59: "Become a DishNet Distributor" page integrated; applications captured in the plugin (docs/48 §12)

Built, tested and **held — nothing deployed.** On your approval (*"integrate into live website"*; submissions
**"Capture in the plugin"**; search **"Yes, index it"**) the recruitment page prototype (docs/48) became a
live site citizen and its wizard now submits to a new plugin endpoint that stores applications for staff
review — the front door to the future distribution module (docs/47).

**1. Currently configured.** The page existed only as a noindex demo (`become-a-distributor.html`, commit
`9ebf2c2`) that saved nothing. The plugin had no intake endpoint and no applications table.

**2. Why.** Put the page live and capture submissions so staff can review them. A recruitment page that goes
nowhere — or that silently drops a submission — costs trust.

**3. What changed.**
- **Website (commit `babce15`):** `become-a-distributor.html` — noindex removed; favicon / fonts /
  LocalBusiness JSON-LD added; links made relative; the final step POSTs `payload`/`hp`/`t` (form-encoded,
  so no CORS preflight) to `…/public.php?page=distributor_apply`; success shows a `DNP-NNNNN` reference;
  **WhatsApp fallback** if the endpoint is unreachable. Footer link on 38 pages, reseller CTA, sitemap entry.
- **Plugin 5.18.59 (commit `d6d0a2e`):** migration 077 `dist_partner_applications` (additive, idempotent);
  `DistributorApplicationService::normalise()` (server-side validation/sanitisation — model + ids
  whitelisted, labels derived server-side, control chars stripped, lengths capped); the public
  `distributor_apply` endpoint (origin allow-list, never `*`; OPTIONS; POST-only; honeypot; minimum
  fill-time; per-IP rate limit; 20 KB cap), routed before `requireLogin()`; a read-only admin review tab,
  **Uganda-tenant-gated** so South Sudan's admin UI is byte-for-byte unchanged.
- **The boundary:** an application is NOT an approval — no uCRM client, partner, service or account is
  created, and no portal access is granted. Appointing a partner stays a separate staff act (docs/47).

**4. Effect on UISP/uCRM.** None. The endpoint makes no uCRM call and the table is the plugin's own SQLite;
nothing is created in uCRM. The website half is independent of UISP entirely.

**5. Rollback.** Website: revert the merge on `main`, rebuild `web-uganda`. Plugin: the separate
`scripts/deploy-5.18.59.sh --rollback` (typed `ROLLBACK`) — printed on its own at the end of the deploy log,
never pasted with the deploy — returns 5.18.58; the new table and any rows simply stay, unread by 5.18.58, so
no data restore is needed (the release changes no existing table).

**Tests:** headless wizard **47/0**; `verify-address.py` 58 pages; `test_distributor_apply.php` **61/0**; the
South Sudan golden stays green; full plugin suite green; `deploy-5.18.59.sh` rehearsed **85/0 twice**.

**Held — two operator-run deploys, each awaiting your explicit go-ahead:**
- **Website** → merge the branch to `main`, rebuild `web-uganda` on EasyPanel, run `verify-site.sh` /
  `verify-address.py`. Safe to do first: the WhatsApp fallback covers the window before the endpoint is live.
- **Plugin 5.18.59** → operator-run `scripts/deploy-5.18.59.sh` (typed `DEPLOY`). Baseline-gated on 5.18.58,
  backs up first, applies migration 077, verifies the endpoint's guards and that its own probes created no
  application row. The rollback is the script's own `--rollback`, printed at the end of the log.

**Plugin deployed 2026-10-01 06:22–06:23 UTC — PASSED 27 / 0 / 0.** The operator ran
`scripts/deploy-5.18.59.sh` on the server: live `fcab6bd` (5.18.58) → `d6d0a2e` (5.18.59); backup at
`/root/dnb-5.18.59/backup-20261001T062238Z` (plugin.sqlite3 24M + data dir + installed 5.18.58 code + vault);
**migration 077 applied** (`dist_partner_applications` + both indexes, 0 rows); every endpoint guard verified
over the live URL (OPTIONS 204/403, GET 405, honeypot/too-fast/empty POST all refused); **R4 — the deploy's
probes created no application row**; **R5 — no uCRM reference in the installed endpoint/service**; R7 — all 144
prior-release files intact; retailers table unchanged (5 rows); no fatal in the container log. The separate
`--rollback` command was printed at the end of the log, not alongside the deploy.

**The capture endpoint is now live and ready. The WEBSITE half is still held** — until
`become-a-distributor.html` reaches `main` and `web-uganda` is rebuilt, the live site does not yet point real
submissions at the endpoint (and the page carries a WhatsApp fallback regardless). **No uCRM record is created
anywhere.**

## 01 Oct — 5.18.60: customer e-mails can CC the client's other contacts; payment reminders can go by e-mail

Built, tested and **held — nothing deployed, and nothing turned on.** On your request (*"we need to send
reminder on email as well as per configured in crm if we have more than one then first one as main and rest in
cc"*) and your two choices — CC scope **"All customer emails"**, overdue on prepaid **"Before-due only"** — the
plugin gains two operator-facing delivery options. **Both ship OFF.** Deploying this release changes nothing a
customer receives until you turn a switch on with `tools/set_customer_emails.php`.

**1. Currently configured.** Every customer e-mail (welcome, invoice, receipt, quotation) goes to exactly one
address — the client's main/billing contact — and no one is copied. Payment reminders go out by **WhatsApp only**;
there was no e-mail reminder path, and on prepaid the overdue e-mail ladder stays suppressed (no "suspension"
wording). A uCRM client with several contact e-mails had the others reach none of the mail.

**2. Why.** You asked for reminders to also go by e-mail, and for all customer mail to copy the client's other
contacts as configured in uCRM (first contact as the main recipient, the rest in CC). A business with a billing
clerk, an owner and an office address should see the same invoice reach all three.

**3. What changed (plugin 5.18.60, commit `3b5e61f`).** Code-only — no migration, no new table, no admin tab, no
website change, no cron-schedule change.
- **`email_cc_contacts` (OFF by default).** When on, **every** customer e-mail goes **To** the main/billing
  contact and **CCs every OTHER distinct contact e-mail** on that uCRM client. The To is unchanged; CC is purely
  additive. CC is delivered for real — a `Cc:` header **and** one `RCPT TO` per copied address — and an
  invalid/refused CC is logged and skipped, never sinking the send. Applies to welcome, invoice, receipt,
  quotation and the new reminder.
- **`reminder_email_enabled` (OFF by default; needs the master switch on).** When on, the Uganda payment-reminder
  cron sends a **before-due** reminder by e-mail (7/3/1 days) **alongside** each WhatsApp. It is prepaid-safe —
  it never threatens suspension or cut-off — and it is **not** a catalogue template, so the Email Preview screen
  and the South Sudan install are byte-for-byte unchanged. The overdue tiers stay suppressed on prepaid, as
  before.
- **OTP / login-code e-mail is NEVER copied.** A login code goes to one person, by design; `OtpEmail` sets no Cc
  and resolves no contacts. The deploy script asserts this (R3) against the installed file.
- New `lib/EmailRecipients.php` (the To/CC resolver), a read-only `tools/mail_log_doctor.php` diagnostic, and
  `tests/test_reminder_email_cc.php`.

**4. Effect on UISP/uCRM.** None written. The CC addresses are **read** from the uCRM client's own contacts; the
reminder e-mail uses the plugin's existing SMTP (Brevo relay). No uCRM record is created or changed, no schedule
changes, and the South Sudan install is untouched.

**5. Rollback.** The separate `scripts/deploy-5.18.60.sh --rollback` (typed `ROLLBACK`) — printed on its own at
the end of the deploy log, never pasted with the deploy — returns 5.18.59. The release changes no table and no
row, so a rollback needs no data restore; it also wrote no config, so the switches are untouched either way.

**Tests:** `test_reminder_email_cc.php` **25/0** (EmailRecipients; CC delivered through a fake SMTP — To + both
CCs in `RCPT TO`, Cc header present, off = single recipient; the reminder render is prepaid-safe and gated;
`reminder_due` not in the CATALOGUE; OtpEmail carries no Cc). Full plugin suite **11,501 / 0** across 253 files.
`scripts/deploy-5.18.60.sh` rehearsed **84/0 twice** (`scripts/harness/deploy-5.18.60/rehearse.sh`), including:
the base gate (5.18.59 first, or NO-GO), V3/R2 reading both switches off on the live install through the plugin's
own tool and still reading the live state when one is flipped on, R3 proving the installed OTP e-mail carries no
Cc (with a control-on-the-control: a Cc planted in OtpEmail is caught, and a blinded copy is not), R5 proving
`reminder_due` is not a catalogue template, R6 proving Release A→5.18.59 are installed as pinned, and the rollback
to 5.18.59.

**Held — one operator-run deploy, awaiting your explicit go-ahead:**
- **Plugin 5.18.60** → operator-run `scripts/deploy-5.18.60.sh` (typed `DEPLOY`). Baseline-gated on 5.18.59,
  backs up first (the two databases + data dir + installed 5.18.59 code + vault), copies the code, and verifies —
  including that **both new switches still read off** on the live install (the deploy turns nothing on). The
  rollback is the script's own `--rollback`, printed at the end of the log.

**After it is live, the two switches are yours to turn on, deliberately, one at a time:**
- CC every customer e-mail to the client's other contacts:
  `docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_customer_emails.php --cc on`
- Payment reminders by e-mail (before-due only, alongside WhatsApp — needs the master on):
  `docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_customer_emails.php --master on --reminder-email on`
- See the current state any time: `… set_customer_emails.php --show`. Turn everything off at once:
  `… set_customer_emails.php --all-off`.

**OTP / login-code e-mail is never copied**, whatever these switches are set to.

**Plugin deployed 2026-10-01 12:08 UTC — code live; both switches then turned ON by the operator.** Live
`d6d0a2e` (5.18.59) → `3b5e61f` (5.18.60); backup at `/root/dnb-5.18.60/backup-20261001T120814Z` (plugin.sqlite3
24M, integrity ok, 230 tables + data dir + installed 5.18.59 code + vault). **The code verified: R1 all 12
changed files byte-for-byte + manifest 5.18.60; R2 both new switches read off; R3 OTP e-mail carries no Cc; R4
the CC + reminder code installed; R5 reminder_due not a catalogue template; R6 all 149 Release-A→5.18.59 files
intact; V4 no fatal since the deploy.** Container PHP 8.1.34.

- **Three V-stage checks FAILED with HTTP `000`, and they are NOT customer-facing.** The script derived the public
  address as `https://crm.dishnetuganda.com:8443/…` and `curl`-ed it **from the server's own shell**, which cannot
  reach the `:8443` public port from inside the host (hairpin) → `000` (no connection). This release changed **no**
  sign-in or routing file (R6: the 149 prior files are byte-intact; the sign-in code is identical to 5.18.59, which
  served fine at its 06:22 deploy), and V4 found no fatal — so the sign-in page is unaffected; the `000` is the
  probe's reachability to `:8443`, not the page. **Defect in the 5.18.60 deploy script, now fixed:** it dropped the
  `:8443` guard the 5.18.59 script carried, and treated an unreachable-from-the-server public URL (`000`) as a hard
  FAIL instead of a NOTE. Patched so `--after-only` re-runs clean; the live plugin is unchanged by that patch.
- **Both delivery options are now ON (the operator's two commands, each read back and verified by the tool):**
  `email_cc_contacts` **ON** — every customer e-mail now also CCs the client's other uCRM contacts; and
  `reminder_email_enabled` **ON** (master was already ON) — before-due payment reminders now also go by e-mail
  alongside WhatsApp. The 8 lifecycle events were already ON before this release; the CC rides on them. Wording is
  reviewable at Admin → ✉️ Email Preview, and everything stops at once with `set_customer_emails.php --all-off`.
  **OTP / login-code e-mail is still never copied.**

**The feature is live and active.** Do not roll back — customers are not affected.

## 01 Oct — "Become a DishNet Distributor" page LIVE on the website (docs/48 §12.3)

The held website half of the 5.18.59 distributor-recruitment integration is now **live**:
`https://dishnetuganda.com/become-a-distributor.html` answers **`HTTP/2 200`**. The recruitment funnel is
connected end to end — live site → the plugin's `distributor_apply` capture endpoint → the **Distributor
Applications** staff-review tab. **An application is still an expression of interest, not an approval:** no
uCRM client, partner, service or account is created; appointing a partner stays a separate staff act
(`docs/47`, unbuilt).

- **Pre-publish honesty fixes (commit `a7e4b62`, on `claude/study-this-jhe2eg`).** The 5.18.59 integration
  (`babce15`) wired the submit to the live endpoint but left the page's prototype clothing, and it had never
  been run through `verify-site.sh` (the website half was held). Caught and fixed before publishing: removed
  the "Prototype · Demo" badge, the *"Demo mode: your answers are not sent or saved"* line above Submit, and
  the footer *"not a live application form"* — all false now the submit is real; the honest *"expression of
  interest only"* notice stays. The WhatsApp fallback button is built via `JSON.stringify` so the site
  link-checker no longer reads it as broken, and `verify-site.sh` now allows the intended `distributor_apply`
  intake URL. `verify-site.sh` PASS (58 pages, 67 refs 0 broken, one portal URL, no price leaks),
  `verify-address.py` PASS, inline JS `node --check` OK.
- **Confirmed live on the server:** the page carries `page=distributor_apply` and **none** of the demo
  strings, so it is the fixed version, not a stale cache.
- **Publish path:** `web-uganda` (EasyPanel) builds the live site from the **branch**
  (`claude/study-this-jhe2eg`), not from `main` — so `main` was not the deploy path. PR #18 (branch → `main`),
  opened as the publish route, was **closed as not required**; reopen/merge only to keep `main` in sync.
- **Still to confirm:** a real wizard submit returning a `DNP-NNNNN` reference into the Distributor
  Applications tab. If the submit shows the WhatsApp fallback instead of a reference, add `dishnetuganda.com`
  to the plugin's `site_origins` (the application is still stored either way).
- **Rollback:** revert the page on the branch and rebuild `web-uganda`. The plugin's capture table/data are
  untouched by the website.

## 01 Oct — 5.18.61: distributor registry (WS-A P1a of the docs/49 plan) — BUILT, off by default, NOT deployed

The first appointed-distributor **entity**, the smallest independently-releasable batch of the distributor
plan the operator approved (docs/49, §15; operator "go P1a"). It is a **local record only** — it creates no
uCRM client, grants no account/login/wallet/portal, and makes no uCRM call anywhere. Build only; **no deploy,
no production change, no real records.** The riskiest piece (linking a uCRM company client — the coherence
operation) is deliberately split out as its own later batch (P1b).

**1. What changed (plugin 5.18.61).** All additive, and the whole feature is gated **twice**: the
`distributors_enabled` config flag (**default off**) *and* the existing Uganda tenant gate. South Sudan and a
flag-off Uganda install are unchanged.
- `migrations/078_distributor_core.sql` — `dist_partners` (docs/47 §9.2 shape; `status` starts `prospect`,
  `ucrm_client_id` nullable until active) + `dist_appointment` (application→partner provenance, one per
  application). Additive, idempotent; nothing in 001–077 touched. **No phone column — dedupe is structurally
  never by phone** (docs/47 §9.1; the wrong-customer-disclosure rule).
- `lib/DistributorRegistry.php` — create / get / list / setStatus / `appointFromApplication`. Dedupe by
  normalised TIN (unique where present) and uCRM id (unique where linked); a duplicate TIN is a **refusal**,
  never an upsert. Actor passed from the identity boundary, never a request field. No uCRM, ever.
- `tabs/admin/distributors.php` — admin-only (defence-in-depth `$isAdmin` check **and** the flag), lists
  partners, and appoints a `DNP-…` application as a local prospect. `includes/post/post_distributors.php` —
  the `dist_appoint` POST, `requireAdmin()` + the global CSRF gate + the flag + Uganda gate.
- `public.php` — three additive lines (tab file, `*admin` perm, Uganda+flag-gated nav module);
  `includes/post_handlers.php` — one additive `require`. Manifest → **5.18.61**.

**2. Tests.** New `tests/test_distributor_registry.php` — **59 assertions, 0 failed** (migration facts;
create/dedupe/enum guards each with a positive control; appointment + provenance + one-per-application;
**the headline: two applications sharing a phone appoint to two distinct partners**; the no-uCRM boundary by
source grep; the wiring + double gate; off-by-default). Regression subset green: `test_distributor_apply`
61/0 (version pin bumped 5.18.60→5.18.61), `test_migration_integrity` 28/0 (078 applies + is recorded),
`test_schema_doctor` 19/0, `test_whatsapp_admin_only` 104/0 (tab perms intact), **South Sudan golden 51/0/0
(admin UI renders byte-for-byte identically — the Uganda-gated nav never touches SS)**. Full plugin suite run
as the after-gate. All changed PHP `php -l` clean.

**3. Not done, deliberately (await their own approval).** P1b (link a uCRM company client — the one uCRM
write), territory + attribution (P2), the distributor-notification pilot (P3), the partner portal (P4,
operator deferred per B-7), and WS-B (per-distributor own-number WhatsApp + AI — committed, Evolution + 21
DishNet-owned SIMs). No operability toggle for the flag ships in P1a; enabling it is a controlled config set
at the (separately-approved) deploy step.

**4. Deployment.** **None in this batch.** When approved it will follow the pinned `deploy-5.18.NN.sh` +
rehearsal pattern, baseline-gated on 5.18.60, flag staying off on deploy. Rollback is trivial: the flag off
restores prior behaviour exactly, and migration 078 only *adds* two unused tables.

## 01 Oct — 5.18.62: distributor → uCRM company-client LINK (WS-A P1b) — BUILT, off by default, NOT deployed

On the operator's "proceed p1b" (after the P1a verification gate reported green). Links an appointed
distributor partner to an **existing** uCRM company client. **No migration** — migration 078 already carries
`ucrm_client_id` / `ucrm_linked_by` / `ucrm_linked_at`. **Reads uCRM to verify and cache; never creates or
modifies a uCRM record.** Build only; **no deploy, no production change, no real uCRM record touched.** Still
behind the `distributors_enabled` flag (default off) and Uganda-gated.

**1. What changed (plugin 5.18.62).**
- `lib/DistributorRegistry.php` — new `linkUcrmClient(partnerId, ucrmClientId, $crm, actor)`: reads the client
  (`$crm->get("clients/{id}")`), requires a **company** (`clientType=2` / `companyName` present) with a legal
  name, **dedupes by uCRM id and normalised TIN — never phone**, refuses a client already linked to another
  partner and a TIN another partner holds (**conflicts flagged for review, never merged or re-pointed**),
  caches uCRM's company fields (uCRM is master), and stamps who/when. A same-id re-link is an idempotent no-op;
  a different id is refused (relink is a separate, audited action). The partial unique indexes on
  `ucrm_client_id` and `tin_norm` are the **floor** beneath the app checks (a race fails there and reports a
  conflict).
- `includes/post/post_distributors.php` — new `dist_link_ucrm` POST: `requireAdmin()` + the global CSRF gate +
  the flag + Uganda gate; builds `CrmApiClient::fromUcrm()` and calls `linkUcrmClient`. **Issues no uCRM write
  of its own** (no POST/PATCH/DELETE, no `createClient`).
- `tabs/admin/distributors.php` — the uCRM column renders an inline **Link** form (admin, CSRF) for unlinked
  partners, with a confirm that states it reads an existing client and creates nothing; linked partners show
  the id. Manifest → **5.18.62**.

**2. Tests.** New `tests/test_distributor_link_ucrm.php` — **33 assertions, 0 failed** — drives the link
through a `FakeCrm extends CrmApiClient` (overrides `get()`, records every call): success + field caching;
idempotent same-link; already-linked-other; duplicate uCRM id (conflict); individual-not-company; missing
company name; **normalised-TIN collision (conflict)**; uCRM error/timeout; not-found; not-configured; the
unique index as the floor; **never by phone** (the method body reads no phone field); and the admin+flag+Uganda
gating. **Read-only proven**: across the whole run the fake recorded only `GET` calls — **no POST/PATCH/DELETE,
so no uCRM client was created or modified.** `test_distributor_registry.php` updated (**57/0**) — its P1a-era
"post_distributors.php does not use CrmApiClient" assertion was deliberately re-scoped to the P1b boundary (the
handler may READ uCRM; it creates nothing), with the reason recorded in the test. `test_distributor_apply.php`
pin → 5.18.62 (61/0). South Sudan golden **51/0** (admin UI byte-for-byte unchanged). Full suite as after-gate.
All changed PHP `php -l` clean.

**3. Not done, deliberately.** No unlink/relink operation (a separate audited action if ever wanted); no
status automation on link; no territory/attribution (P2); no notifications (P3); no portal (P4); no WS-B.

**4. Deployment.** **None.** P1a (5.18.61) and P1b (5.18.62) deploy together when approved, as one pinned,
rehearsed `deploy-5.18.NN.sh`, flag staying off. The one production behaviour P1b adds — a uCRM **read** when
an admin links a client with the flag on — only ever runs post-deploy, admin-triggered. Rollback: flag off
restores prior behaviour; no schema change to revert.

## 01 Oct — 5.18.63: distributor territory + customer attribution (WS-A P2) — BUILT, off by default, NOT deployed

On the operator's "P2 (territory + attribution) go ahead". Adds the attribution spine: a distributor's
**territory** (areas/districts) and the **structured customer/lead → distributor owner** link. **Local only;
no uCRM, no messages, no production change.** Behind `distributors_enabled` (default off) and Uganda-gated.

**1. Files & migration.**
- `migrations/079_distributor_territory.sql` — `dist_regions` (docs/47 §9.3), `dist_territory_map`
  (area_key → region → distributor), `dist_customer_links` (scope ∈ {ucrm_client, lead}, entity_id, partner,
  assigned_via, active, history). Additive/idempotent; 001-078 untouched. **No phone column anywhere.**
- `lib/DistributorAttribution.php` — `addRegion`/`addArea` (territory), `candidateFor` (resolver), `link`
  (attribution), `activeLink`/`history`/`territory`/`linksForPartner`.
- `tabs/admin/distributors.php` — a Territory & attribution section (add region, add area, the territory map
  table, and a confirm-owner form). `includes/post/post_distributors.php` — `dist_region_add`/`dist_area_add`/
  `dist_attribute` POSTs, admin + CSRF + flag + Uganda. Manifest → 5.18.63; three version pins updated.

**2. Rules baked in (docs/49 §6), each proven.**
- **Never by phone** — no phone column; `link()` takes/reads no phone (asserted on the method body); keyed by
  the uCRM client id or lead id.
- **One owner at a time** — one *active* link per (scope, entity_id), enforced by a partial unique index;
  re-attributing **supersedes** the old row (kept as history with `superseded_at`), never a silent overwrite.
- **One distributor per area** — `area_key` is globally unique; an area already another distributor's is
  refused and flagged, never merged; so a territory resolve returns at most one candidate (ambiguity → null).
- **Human-confirmed (pilot, B-6)** — `candidateFor()` only *proposes*; the admin confirms the owner via the
  form. Nothing auto-attributes a real customer.

**3. Tests.** `tests/test_distributor_territory.php` — **47/0**: migration + no-phone invariant; region dup
refused (+control); area normalisation + one-area-one-distributor conflict (+control) + idempotent; resolver
proposes one / fails safe to null / case-insensitive; attribution active link, **relink supersedes with
history kept and exactly one active** (+the partial-unique-index floor proven by a direct 2nd-active INSERT
being refused, with the inactive-row control); scope/via/entity/partner guards; scope separates a lead from a
client with the same id; handlers admin+flag+Uganda; tab forms; no uCRM. Regression: registry 57/0, apply
61/0, link 33/0, **South Sudan golden 51/0 (admin UI byte-for-byte unchanged)**. Full suite as after-gate.
`php -l` clean.

**4. Deployment.** **None.** P1a+P1b+P2 (5.18.61→.63) deploy together when approved, flag off. Rollback:
flag off restores prior behaviour; migration 079 only *adds* three unused tables. Preserves Uganda/South
Sudan; Domain B untouched. **Not built, deliberately:** `dist_outlets` (stock-side, a later phase);
auto-attribution of real customers on the customer/lead screens (the resolver is the spine; that wiring is a
separate step); P3 notifications; the partner portal (P4).

## 01 Oct — 5.18.64: distributor notifications, pilot core (WS-A P3) — BUILT, off by default, NOTHING SENT, NOT deployed

On the operator's "p3 go ahead". When something happens to a distributor's own customer or lead — a lead
attributed, a payment received, a new customer activated (B-5's three events) — an alert is **drafted** for a
DishNet admin to approve. **Draft → approve, never auto-send (B-4). NOTHING is sent to a real number in this
pilot:** the bound transport is a Null channel, so approving *queues* an alert; connecting a live WhatsApp
number is a separate, explicitly-approved step. **Local only — no uCRM write, no message, no production
change.** Behind `distributors_enabled` (default off) and Uganda-gated.

**1. Files.**
- `migrations/080_distributor_notifications.sql` — `dist_contacts` (a distributor's **verified** number;
  `verified` defaults 0, never trusted from a form), `dist_notify_consent` (the distributor's own `muted`
  flag — the only suppressor), `dist_notify_log` (the draft→approve outbox; `status`
  draft|approved|sent|rejected|suppressed; `dedup_key` **UNIQUE** = the once-per-(distributor,event,entity)
  claim floor). Additive, idempotent; nothing in 001–079 touched.
- `lib/WhatsAppChannel.php` — the provider **port** (docs/49 §3.3). `NullWhatsAppChannel` is bound in the
  pilot (sends nothing); `EvolutionWhatsAppChannel` (a thin wrapper over the existing `EvolutionApiService`)
  exists so the port has its one real adapter but is **constructed nowhere** — the "one adapter live at a
  time, never a second integration" rule.
- `lib/DistributorNotifier.php` — builds a privacy-guarded draft, checks the distributor's own consent, claims
  the dedup key, resolves the verified recipient; `approve()` hands an approved draft to the channel (Null →
  queued, never sent); `reject()`; contacts + consent management.
- `lib/DistributorEvents.php` — the one flag-gated, **try/catch-isolated** entry point every event source
  calls. A strict no-op unless Uganda **and** the flag; resolves the owning distributor from
  `dist_customer_links` (079); builds a draft only. Any throw is swallowed — safe to drop into a live path.
- `includes/post/post_distributors.php` — attributing a **lead** now fires `lead_attributed` from our own
  handler; new admin actions `dist_contact_add` / `dist_contact_verify` / `dist_consent_mute` /
  `dist_notify_approve` / `dist_notify_reject`, all admin + CSRF + flag + Uganda.
- `tabs/admin/distributors.php` — a **Notifications** section: verified numbers & consent per distributor, the
  draft→approve queue, recent activity. Copy says plainly nothing is sent in the pilot.
- **`webhook.php` — two thin, flag-gated, try/catch-isolated hooks** (the one live-file change this phase):
  the `payment.add` handler (after the response is flushed, off the uCRM-**re-verified** payment) and
  `service.add` on first activation (`status === 1`). Each calls `DistributorEvents::maybeNotify()` and is a
  **strict no-op** when the pilot is off — every South Sudan install, and Uganda by default. Proven below.
- Manifest → 5.18.64; the four distributor-test version pins updated.

**2. The rules, each proven.**
- **Draft → approve, no live send.** The bound channel is Null; `approve()` queues (`approved`), it does not
  send. A fake *live* channel in the test proves the approve→send path exists and works, but no live adapter
  is bound in the pilot.
- **Privacy (docs/49 §11).** Every draft body passes `ReplyPrivacyGuard::check()` with an allow-list of **only
  the owning customer's own** amount/ids, so a foreign customer's value is blocked (`foreign:amount`) and
  never queued. An edited body is re-checked on approve.
- **Consent (CLASS_DISTRIBUTOR).** A customer's opt-out never suppresses a distributor alert (the recipient is
  the distributor, and the design routes nothing through a customer opt-out); only the distributor's own
  `muted` does.
- **Exactly once.** The UNIQUE `dedup_key` is the floor — a webhook replay or double-submit creates no second
  draft.
- **Verified recipient only.** A number is a destination only when `verified = 1`; 0 or >1 verified resolves
  to none (ambiguous, fail safe).
- **Never by phone for ownership.** The owner comes from `dist_customer_links` (079), keyed by the uCRM client
  id or the lead id; a phone here is only a verified destination.

**3. Tests.** `tests/test_distributor_notify.php` — **66/0**: migration + dedup floor; contacts (0/1/>1
recipient resolution); consent; the three events → draft, dedup once, suppressed-when-muted; **privacy — a
foreign amount blocked, a paired allow-list control, and a weakened copy of the notifier (guard removed) that
lets the value through, proving the guard has teeth**; draft→approve with Null (queued) and a fake live channel
(sent); reject; the Uganda+flag gate (on/off, owned/unowned); the DB unique floor (+control); wiring/gating;
the port (Null bound, Evolution adapter present but unbound); no uCRM. Existing distributor suites green with
pins bumped: registry **57/0**, apply **61/0**, link **33/0**, territory **47/0**. **South Sudan golden 51/0 —
admin UI and the whole job-day (every uCRM call, WhatsApp text, webhook log, e-mail) byte-for-byte unchanged,
all 11 mutants still caught** — proving the `webhook.php` hooks are strict no-ops off. Full suite as after-gate.

**4. Deployment.** **Built and rehearsed, NOT run.** On the operator's go-ahead, `scripts/deploy-5.18.64.sh`
(pinned to `03df9a5`, installs P1a+P1b+P2+P3 over live 5.18.60) with `distributors_enabled` left OFF — backup +
GO/NO-GO, byte-for-byte verify, the three additive migrations (078/079/080) apply on the next request, R4 proves
the Null channel is bound so nothing can be sent, and the rollback to 5.18.60 is a separate command (never pasted
with the deploy). `tools/set_distributors.php --on|--off|--show` is the operator toggle (Uganda only; still sends
nothing). The rehearsal `scripts/harness/deploy-5.18.64/rehearse.sh` drives it end to end against a fake 5.18.60
server with teeth + a mutant control: **88/0**. This session cannot reach the server; the operator runs the
one-line command and sends back the log. Rollback: flag off, or the script's `--rollback`; the additive tables
stay empty and ignored by 5.18.60. Preserves Uganda/South Sudan; Domain B untouched. **Not built, deliberately:**
the live WhatsApp transport (a separate, approved step — the pilot queues, never sends); the partner portal (P4);
WS-B (per-distributor own-number WhatsApp + AI).

## 01 Oct — 5.18.65: Distributors link in the left sidebar (Admin section) — DEPLOYED to production 21:25 UTC (PASSED 18/0/0)

On the operator's "yes go ahead" to putting the Distributors screen in the side menu. 5.18.64 deployed the pilot and
the operator turned it on, but the registry was reachable only by typing the `?page=dashboard&tab=distributors` URL —
the left sidebar (`includes/navigation.php`) is hand-curated and had no link. **One UI change over 5.18.64:** a
"Distributors" link in the left sidebar's **Admin** section. **No migration, no new behaviour, the pilot switch
untouched.**

**1. Files.**
- `includes/navigation.php` — a self-contained, self-gated block in the Admin section (after Overdue Workbench): the
  link renders only when `$isAdmin` **and** Uganda (`StaffJobsGate::applies`, fail-closed) **and**
  `!empty($config['distributors_enabled'])` — the exact triple gate the tab (`tabs/admin/distributors.php`) and the
  `$ALL_MODULES` menu entry (`public.php`) already use. On South Sudan, and on Uganda while the flag is off, the block
  is pure PHP that emits **zero bytes**. It re-`require`s `StaffJobsGate` and re-checks `$isAdmin` itself, so it cannot
  leak if moved.
- `manifest.json` → 5.18.65. Five distributor test files: version pins 5.18.64 → 5.18.65.

**2. Tests.** `tests/test_distributor_registry.php` (**59/0**) gains two assertions: the sidebar carries
`tab=distributors`, and the admin+Uganda+flag gate sits within the 8 lines before it (a copy that drops the gate
fails). **South Sudan golden 51/0** — the Staff page, dashboard and the whole job-day render **byte-for-byte**
identical to 5.18.49, all 11 mutants still caught — proving the sidebar block emits nothing on South Sudan. Existing
distributor suites green with pins bumped (apply 61/0, territory 47/0, notify 66/0, link 33/0). Full plugin suite
**green, run twice** (`run.sh` exit 0 both runs, 0 failures).

**3. Deployment. DEPLOYED to production 2026-10-01 21:25 UTC — PASSED (18 ok, 0 failed, 0 notes).** The operator ran
the pinned command; the log shows: plugin commit `ce3fa91` over live `03df9a5` (5.18.64), 7 files byte-for-byte (R1),
the pilot switch read **on** before and after and unchanged by the deploy (V3/R2), no migration (R3), the Null channel
still bound — nothing can be sent (R4), Release A→5.18.64 preserved (173 files, R5), and the **Distributors sidebar
link installed and gated (R6)**. The **Distributors** link is now live under **Admin** in the left sidebar on the
Uganda install. `scripts/deploy-5.18.65.sh` (pinned to `ce3fa91`, installs
5.18.65 over live **5.18.64**) — backup + GO/NO-GO, byte-for-byte verify (R1), the pilot switch **unchanged** by the
deploy (V3/R2 read the live state before and after and require them equal — whatever the operator set it to, it
stays; even on, nothing is sent), **no migration** (R3 is a regression check that 5.18.64's three are still present
and the six tables intact), the Null channel still bound so nothing can be sent (R4), Release A→5.18.64 preserved
(R5), and the **Distributors sidebar link installed and gated (R6)**. The rollback to 5.18.64 is a **separate**
`--rollback` command (never pasted with the deploy, root docs/44 §16.9); it restores code only, so the pilot, its
config and its tables are untouched and the screen stays reachable at the URL. The rehearsal
`scripts/harness/deploy-5.18.65/rehearse.sh` drives the deploy + checks + rollback end to end against a fake 5.18.64
server (pilot **ON**, as the live one is) with R1/R6/R5 teeth and an R4 control-on-control mutant: **87/0**. This
session cannot reach the server; the operator runs the one-line command and sends back the log. Preserves
Uganda/South Sudan (South Sudan sees nothing); Domain B untouched. **Not changed:** the pilot itself, the Null
transport (still queues, never sends), P4, WS-B.

## 02 Oct — WS-A P4: distributor-portal OTP over the existing Evolution WhatsApp + admin TOTP reset — BUILT in development, NOT deployed, NOTHING SENT

On the operator's implementation approval of `docs/53` (decision **D2 — reuse the existing support instance
`evo_instance_support`; fake-Evolution tests only; real sending a separate future gate**). This fills the delivery
seam the P4 sign-in (P4c/P4d) left null. **Development and test schema only. No real WhatsApp send, no live-provider
test, no deployment, no flag change, the portal stays OFF.** South Sudan and Uganda (flag off) are unchanged; Domain B
is untouched.

**1. Files.**
- `migrations/083_distributor_portal_otp_delivery.sql` — **new** `dist_partner_auth_log`, the non-secret
  authentication-plane record (OTP-send outcome + TOTP-reset audit). Additive, idempotent, inert until the flag is on;
  nothing in 001–082 touched. A CHECK constrains `otp_send` outcomes to `accepted / failed / unknown / no_recipient` —
  **there is deliberately no `delivered` value, so Evolution's acceptance can never be written as delivery.** No
  `code` / `secret` / `token` column.
- `lib/PartnerOtpSender.php` — **new**. `fromConfig()` binds `EvolutionWhatsAppChannel` on `CHANNEL_SUPPORT` (D2) over
  the EXISTING `EvolutionApiService` — the same `WhatsAppChannel` port the distributor notifications use, **not**
  `NotificationService`, message class `CLASS_STAFF`. `resolveRecipient($userId)` derives the destination **server-side
  from the account alone** → the account's OWN verified `dist_contacts` number → else `null` (no send). `send()` calls
  the channel **exactly once** (never a self-retry), maps the result to `accepted / failed / unknown`, records a
  non-secret row; the login request's number is ignored.
- `lib/PartnerAccounts.php` — **+`resetTotp($userId,$actor)`**: clears the authenticator, revokes every live session,
  and audits, **in one transaction** (fail-closed). Actor from the identity boundary. Mirrors `disable()`/`setRole()`.
- `includes/post/post_distributors.php` — **+`dist_totp_reset`** staff handler: `requireAdmin()` + Uganda + flag gate,
  actor = the authenticated admin, calls `resetTotp()`. No self-service.
- `partner_api.php` — **behaviour unchanged: `$deliver = null`** (the live entry binds NO sender); comment refined to
  show the separately-approved wiring.
- `tests/fixtures/fake_evo_server.php` — strictly-additive `code` param on `/__test/fail_next` (default 500) so a test
  can force a gateway 50x; existing callers unaffected.

**2. Tests.** `tests/test_partner_otp_delivery.php` — **68/0**, all synthetic, against the fake Evolution server:
migration 083 (incl. the DB-level `delivered` refusal + control); the server-side verified-only recipient resolver
(unverified / absent / foreign-number / disabled all → no send; resolver takes a user id only, by reflection); a real
`accepted` send on the **support** instance to the **verified** number carrying the code, with the request's bogus
number ignored; `failed` on a provider 500; `unknown` on a gateway 504 **and** on a real Uganda timeout, each reaching
Evolution exactly once and never resent; `no_recipient` with the uniform `{status:"sent"}` response; the per-account
send cap gating sending (four requests, cap two → two sends); the secrets scan (code / TOTP secret / apikey / token in
no log row or response); the admin TOTP reset revoking a live session, clearing the authenticator, and auditing with
the acting admin; the outcome-mapping table with the no-self-retry guarantee (channel called exactly once); the
separation + support-binding checks; the no-accidental-send checks; and **three weakened copies each caught** (a copy
that reads the destination from the request, one that records an uncertain send as `accepted`, one that logs the code).
Full plugin suite **green, run twice** (`run.sh` exit 0, 0 failures). The fake-server change is additive; the
Evolution-dependent suites stay green.

**3. No accidental real send (operator-requested review).** There is no path, under any test or configuration, that
makes the live entry send in this phase: (a) `partner_api.php` keeps `$deliver = null` (asserted as code, comments
stripped), so the live portal produces the code and sends nothing whatever the flag/config; (b) `PartnerApi` hard-codes
no sender — delivery is injected; (c) the portal 404s unless Uganda **and** `distributors_enabled`, and the pilot flag
is off; (d) the sender is constructed only in tests, pointed at the `127.0.0.1` fake, which never contacts WhatsApp —
no test uses a real `evo_api_url`; (e) wiring it live is a visible one-line change, deliberately absent and documented
as the separate gate.

**4. Deployment. NONE — and none is in scope.** Real OTP delivery, wiring the sender into `partner_api.php`, enabling
the portal/flag, P4e (portal pages), P4f (deploy artifacts), staging rehearsal and deployment all remain **separate,
explicitly-approved** steps. `docs/53 §8` is the as-built record. PD-2 / PD-5 / PD-8 remediation (docs/52) is untouched.

## 02 Oct — PD-8: same-site CSRF guard on the staff JSON API (both surfaces) — BUILT in development, NOT deployed

On the operator's approval of the `docs/54` PD-8 design, limited to **cookie-authenticated CSRF protection only**. The
two staff JSON surfaces — `public.php?page=api` and `public.php?page=stock_api` — accept either a Bearer `api_token`
(every integration) or the browser session cookie (`kyc_retailer`, deliberately `SameSite=None` for the uCRM iframe).
Because that cookie rides cross-site requests too, and the JSON path never ran a CSRF check while CORS is `*`, a page a
signed-in staff member visits could drive a "simple" (preflight-free) cross-site write on their behalf. This closes
that. **Development and test only; not deployed; no flag change; CORS unchanged; Uganda and South Sudan preserved;
Domain B untouched.**

**1. Files.**
- `lib/StaffApiCsrf.php` — **new**. One pure method, `mustBlock($authedViaCookie, $method)`. It exempts GET/HEAD/OPTIONS
  (safe methods / preflight), exempts anything **not** cookie-authenticated (Bearer integrations) — keyed off the
  **server-side auth outcome, never a header's presence** — and, for a cookie-authenticated mutation, delegates
  "our own page?" to `CustomerSession::crossSite()`, reused verbatim.
- `includes/api_handlers.php` (`?page=api`) — captures `$authedViaCookie` (false before `tokenAuth()`, true only in the
  session-fallback branch) and refuses `403 cross_site` after the staff auth check; the pre-auth customer-app actions
  exit earlier and are untouched.
- `includes/routes.php` (`?page=stock_api`) — the same, before the stock handler runs.

**2. No static allow-list (a safer deviation from the `docs/54` §6 plan).** `crossSite()` compares the request's
`Origin` to its **own `Host`**, so the Traefik hostname and the UISP `:8443` origin each validate against themselves and
are **not** treated as interchangeable — no list to misconfigure, and the operator's point-5 hard-stop did not arise.
Only `crossSite()` is reused, **not** `cookieUseAllowed()`, which also demands `X-Requested-With: DishNet` — a header the
staff UI does not send.

**3. Tests.** `tests/test_api_csrf_guard.php` — **51/0**: a unit matrix over `mustBlock()` (safe methods, Bearer
exemption, same-origin pass, cross-site block by `Sec-Fetch-Site` and by `Origin`≠`Host`, missing/`null`/malformed
`Origin`, the four Traefik-vs-`:8443` permutations, three weakened copies each caught, and source-wiring assertions
incl. the unchanged CORS `*`); and, over real HTTP on **both** surfaces, Bearer cross-site → allowed, cookie cross-site
→ 403 (incl. the form / FormData / text-plain "simple request" cases), cookie same-origin → allowed, GET → allowed,
invalid-Bearer-then-cookie → blocked, valid-Bearer-plus-cookie → allowed, anonymous cross-site → 401. Full plugin suite
**green, run twice** — `tests/run.sh` exit 0 both times, **263 files / 10,521 assertions / 0 failed** (identical both
passes); `test_api_csrf_guard.php` **51/0**.

**4. Scope / deployment. NONE in scope.** CORS narrowing (step 4), PD-5, PD-2, P4e, portal/flag changes, OTP
wiring/sending, staging and deployment all remain separate, explicitly-approved steps. The manifest version is
unchanged (release/versioning is the operator's step). `docs/54 §10` is the as-built record.

## 03 Oct — 5.18.66: site photos on a job and the technician's location at completion (Uganda only) — BUILT, NOT deployed

On the operator's "ok go ahead", after the question *can a technician attach kit / cable / router photos, stored
compressed at good quality, and can we get the location where the task was finished?* The investigation found the
pieces half-present and none of them live for a Uganda technician: the job page's Complete form sent notes only; the
server kept the first 200 characters of each photo; GPS was accepted but never sent, validated or shown; the live map's
tables lose their keys on read. Built inside **My Jobs** (the installable staff app), gated by `StaffJobsGate` like every
5.18.5x change: **South Sudan keeps 5.18.65 byte for byte** (proved below). **Development and test only; not deployed;
nothing new is written to uCRM; Domain B untouched.**

**1. Files.**
- `migrations/084_job_photos.sql` — **new**: `job_photos` (job id, fixed label, uploader, their verified uCRM link and the
  job's assignee at the time, server-chosen path, mime, bytes, size, sha256) and `job_completion_gps` (one row per job:
  lat/lon/accuracy from the browser, or `source = 'missing'` with the technician's reason). Real tables, deliberately —
  not id-keyed JSON lists (`SqliteStore` `$FLAT_TABLES`).
- `lib/JobPhotos.php` — **new**. Four labels (`kit · cable · model · other`), never free text, so a label can never
  become a path (the `install_photos` flaw); `getimagesize()` decides what a file is; GD re-encodes upright to ≤ 1600 px
  on the long edge, JPEG 82 (a 3000×2000 shot stores at 1600×1067); files under `$dataDir/uploads/job_photos/<job>/`
  (RULE 15), served only from the row and only from under that folder (`realpath` containment). `validateGps()` takes a
  fix (range-checked, `0,0` refused, seven decimals) or a 3–200-character reason — one or the other is required.
- `includes/api/api_job_photos.php` — **new**, loaded after `api_scheduling.php` and reusing its gate, caller and
  `$sjMayAct` (J6): `job_photo_upload` (multipart), `job_photos`, `job_photo_delete` (taker, leader or admin; never while
  the job is closed; ≤ 12 per job, ≤ 8 MB each). Off Uganda the three actions do not exist (404 "Unknown API action").
- `includes/api/api_scheduling.php` — `scheduling_job_detail` carries `photos`, `completion` and `photo_rules` on Uganda;
  `scheduling_complete` on Uganda refuses, **before anything is stored or sent**, an installation (title words:
  install / fiber / fibre / starlink / ftth / lte activation — the invoice-queue rule) missing kit, cable or router
  (config `job_photos_required`, default on), and any completion without a location or a reason; stores the location.
  **The legacy base64 `photos[]` path is untouched** (still truncated, still South Sudan's).
- `tabs/support/scheduling.php` (job page) — a **Photos** card (camera capture, resized on the phone to ≤ 1600 px / 0.82
  before upload, thumbnails, remove) between Tasks and Actions; `window.schOpenCompleteFormImpl` — the hook the
  notes-only form already checked — swaps in the completion form: required-photo status, live GPS with Retry, a reason
  box when there is no fix, notes; a **Completed from** card with a Maps link on closed jobs. All behind `UG_PHOTOS`.
- `includes/routes.php` — `?page=job_photo&id=N`: taker, assignee-at-the-time (verified link), leader, admin, accountant;
  400 for a non-numeric id; the `kyc_photo` path rule and the Photo Manager API gain `uploads/job_photos`.
- `tabs/admin/photo_manager.php` — a *Job Photos* tile and *📷 Jobs* filter. `includes/api_handlers.php` — one `require`.
- Tests: `tests/test_job_photos.php` (**new**); `tests/test_job_access.php` §7 and `tests/fixtures/staff_jobs_scenario.php`
  now complete the Uganda job the way a technician must (photos + a location — a *reason*, so every message stays
  5.18.51's); `tests/fixtures/staff_jobs_sandbox.php` gains `upload()` (multipart); the nine manifest-version pins
  (`test_dist_isolation`, the five `test_distributor_*`, the three `test_partner_*`) read 5.18.66, as every release
  moves them. Nothing else in `tests/` changed.

**2. What was deliberately NOT built.** No uCRM write of the location (a job comment was tried and removed: the day test
guarantees a completion sends uCRM exactly 5.18.51's writes — a toggle later if wanted); no push of photos to uCRM
documents (J11 stays open); no change to the South Sudan `install_photos` flow (its free-text `photo_type` path risk is
recorded for its own fix); no live-map work (its `$FLAT_TABLES` loss is recorded, not built on); the `job_completions`
200-character truncation left as is on the legacy path.

**3. A contract change, on Uganda only.** `scheduling_complete` now answers **422** to a caller that sends neither a fix
nor a reason, or completes an installation without its three photos. The job page always sends one or the other. Any
native caller outside this repository (the Android wrapper's own endpoints are unmeasured) would get the 422 with the
reason in words — nothing half-done.

**4. Tests.** `test_job_photos.php` **72/0**: migration; the re-encode (1600×1067, sha256 of what is on disk, no path in
any answer); nine refusals that change no row, file or uCRM request (unknown label, path-shaped label, a text file
called photo.jpg, no file, another engineer, an unverified link, a sales account, no job, a job uCRM lacks); leader and
admin uploads, a PNG stored as JPEG; completion refused for a missing router photo with uCRM untouched, refused for a
fix out of range / non-numeric / 0,0 / no reason / a two-letter reason, then accepted — stored to seven decimals, uCRM
receiving exactly the status and the note; the detail; a non-installation completed with a reason; the viewer route
(taker / leader / admin 200, another engineer 403, `abc` 400, missing 404, trailing path 400); delete by taker not by
another engineer; a closed job refuses both; **South Sudan control** (actions 404, detail without photos, completion
asks nothing new, no file written); **three weakened copies each caught** (no access check on upload, no required-photo
check, no reason required). Also green after the change: `test_job_access` 83/0, `test_job_notifications_day` **50/0
including the byte-for-byte 5.18.51 baseline comparison on Uganda and South Sudan**, `test_staff_jobs_south_sudan` 51/0,
`test_job_notifier` 130/0, `test_migration_integrity` 28/0, `test_preauth_allowlist` 105/0, `test_api_csrf_guard` 51/0,
`test_staff_jobs_gate` 41/0. The job page's `<script>` block parses under Node. PHP 7.4 syntax throughout.

**5. Deployment — prepared the project's way, NOT run.** Asked "what command do I have to run", the answer followed the
pattern every release since 5.18.39 has used: a deploy script pinned to the reviewed commit, rehearsed against a fake
install, a backup before copying, read-only checks afterwards, a log file sent back.

- **A scope finding first.** `deploy-hybrid.sh` copies the whole plugin tree, and the branch tip (`924cb6f`) carries,
  beyond 5.18.66, the distributor partner-portal stack (WS-A P4a–P4d, `93da47e`…`8227fc8`: migrations 081–083,
  `partner_api.php`, `lib/Partner*.php`, `lib/Totp.php`, `lib/DistributorPortalData.php`) and the PD-8 CSRF guard
  (`675f128`) — each recorded above as "NOT deployed", each with its own approval still to come, the portal with a
  security review in progress (docs/51). Deploying the tip would ship all of it. So the release is cut on the live
  version instead: **`release/5.18.66` = commit `8137912`, parent `ce3fa91` (5.18.65, production since 1 Oct)** — the
  5.18.66 plugin changes applied to it and nothing else (18 files; the four pin bumps for test files that do not exist
  at 5.18.65 left out; code hunks unchanged). On that tree: `test_job_photos` 72/0, `test_job_access` 83/0,
  `test_job_notifications_day` 50/0 with the 5.18.51 baseline, `test_staff_jobs_south_sudan` 51/0,
  `test_migration_integrity` 28/0, `test_staff_jobs_gate` 41/0, `test_distributor_registry` 59/0,
  `test_distributor_apply` 61/0.
- **`scripts/deploy-5.18.66.sh`** (pinned `8137912` over `ce3fa91`), the 5.18.65 script's shape with: **A0** — the
  delta must contain no partner-portal / CSRF file, and the pin's parent must be 5.18.65 (a copy pinned to the branch
  tip is refused before the container is even looked at); the release branch is fetched when the checkout lacks the
  commit; **V5** — the photo viewer sends an anonymous visitor to sign in and `job_photo_upload` answers 401 to nobody;
  **R3** — migration 084 installed, its two tables present-or-lazy with their row counts; **R4** — the pilot regression
  plus "no `partner_api.php` / `StaffApiCsrf.php` installed"; **R6** — the photo surface present and wired to the Uganda
  gate; the rollback (typed `ROLLBACK`) restores 5.18.65's code and leaves the two tables and any photos on disk, unread.
- **Rehearsal `scripts/harness/deploy-5.18.66/rehearse.sh`: 127/0 over 17 runs of the script** — the three NO-GO gates,
  the deploy as the operator runs it, the plugin's first boot (084 additive, `0:0`, no existing row changed), R1/R6, R5,
  V3/R2 and R4 each proved to have teeth (a reverted job page, a changed Release-A file, the switch flipped, a live
  channel and a planted `partner_api.php` are each caught by name), the rollback, and an R1-blinded copy caught.
- **The operator's command** is in the script's header (deploy only — the rollback is printed at the end of the deploy's
  own log, as its own command, never pasted together; root docs/44 §16.9). First use on the server: 084 applies on the
  next plugin request; the data directory gains `uploads/job_photos/`, already inside the Google Drive backup.
- **RESULT — DEPLOYED to production 2026-10-03, 19:39 UTC: PASSED, 21 ok / 0 failed / 0 notes.** The run began at
  19:39:22 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves 8137912"* at 19:39:47 UTC.
  Recorded from the terminal the operator pasted (the script prints no secret); the log file stays on the server as
  `/root/dnb-5.18.66/deploy-20261003T193922Z.log`.
  - **A.** Checkout `fc81066`; branch tip `924cb6f` (not installed); release commit `8137912` cut on `ce3fa91`; 18 files
    (14 changed, 4 added, 0 removed), 1 migration; **A0** — no partner-portal or CSRF file in the delta. Live `ce3fa91`
    / 5.18.65. The container's PHP **8.1.34** accepted all 7 changed server files and the 9 test files; **GD present**,
    so photos are re-encoded server-side. Pilot `on`; photo tables `lazy`; `uploads/job_photos` absent.
  - **Backup** `/root/dnb-5.18.66/backup-20261003T193922Z`: `plugin.sqlite3` 26 MB, one consistent copy, integrity ok,
    239 tables; the data directory 124 MB; the installed 5.18.65 11 MB; the vault. No `dishnet.sqlite` in the data
    directory (nothing to copy). `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal without a session 302; no South Sudan contact; **V5** the photo
    viewer without a session 302, `job_photo_upload` without a login 401; **V3** the pilot unchanged (`on` → `on`);
    **V4** no fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 18 files as `8137912` has them, manifest 5.18.66; R2 `pilot=on`; **R3 migration 084 installed and
    both tables present with `0:0` rows** — the plugin's first request within the guard window created them, additively;
    R4 the pilot libs, the two flag-gated hooks and the Null channel as before, the pilot tables present, **no
    `partner_api.php` / `StaffApiCsrf.php` installed**; R5 all 173 files from Release A through 5.18.65 intact; R6 the
    photo surface installed and Uganda-gated.
  - **A standing hazard this release makes real.** The server checkout (`/opt/dishnet`) sits on the branch tip, which
    now carries undeployed work (the portal stack, PD-8). A bare `bash scripts/deploy-hybrid.sh` there would ship all
    of it. **Deploy only through a pinned `scripts/deploy-5.18.NN.sh` from now on**, and read its `--check` line
    ("NOT up to date") as the documented consequence of installing a release commit, not as a fault.
  - **Not yet seen:** a technician's photo and location on a real job. Next: a phone, signed in as a technician — My Jobs
    → a job → *Take photo* → *Mark as Completed* → *Allow location*; then `bash scripts/deploy-5.18.66.sh --after-only`.

## 03 Oct — 5.18.67: the Site photos card lays out cleanly on a phone (Uganda, My Jobs) — BUILT, rehearsed, NOT deployed

**Seen in production first.** Minutes after the 5.18.66 deploy the operator opened a live job on a phone and sent two
screenshots of the new *Site photos* card (0/12; Kit / dish, Cable used, Router / model, Other) — the first sighting of
5.18.66 running. They also showed the defect: the word *required* sat on the same line as the label, so *Cable used* and
*Router / model* wrapped, the *Take photo* button beside them shrank, and its own text wrapped. "yes fix it."

**1. The change — one hunk, `tabs/support/scheduling.php`, `schRenderPhotos()`.** Each label row is still one flex row,
but the left side is now a column: the label, and under it the status in 11 px — *required* (amber), *✓ added* (green;
was a bare ✓), or *N photo(s)* for an optional label that has some. The button keeps `white-space:nowrap;flex-shrink:0`,
so it holds one line at any width; `min-width:0` on the column lets a long label wrap on its own side instead. Nothing
else: no API, no server code, no migration, no setting, no uCRM write; the card still renders only behind `UG_PHOTOS`, so
**South Sudan is unaffected** (the card does not exist there). `manifest.json` 5.18.67; the nine manifest-version pins
moved. Main commit **`9343f60`** (11 files). Green on it: `test_job_photos` 72/0 and the nine pins; PHP 7.4 syntax; the
job page's `<script>` block parses under Node.

**2. The release commit, as 5.18.66 taught.** The branch tip still carries the undeployed partner-portal stack and PD-8,
so the release is cut on the live version: **`release/5.18.67` = `96857d8`, parent `8137912` (5.18.66, production since
19:39 UTC)** — `9343f60` cherry-picked, the four pins for test files that do not exist at 5.18.66 left out. The code hunks
of `scheduling.php` and `manifest.json` are **identical** between the two commits (diffed). Seven files differ from
`8137912`: the two above and five `test_distributor_*` pins; **no migration, no new file.** On that tree:
`test_job_photos` 72/0, `test_distributor_registry` 59/0, `test_distributor_apply` 61/0, `test_migration_integrity` 28/0.

**3. `scripts/deploy-5.18.67.sh`** — pinned `96857d8` over `8137912`, the 5.18.66 script's shape, with the differences a
code-only release needs: **A0** also refuses a pin whose delta carries *any* migration (5.18.67 adds none — a pin with
one is another build); the parent check still refuses a copy pinned to the branch tip before the container is looked at;
**R3** is now a regression — migration 084 still installed and its two tables present with their row counts (a count is
never a failure; the deploy touches neither table nor the photo folder, both read before and after); **R6** adds the one
marker the new layout introduces (`flex-shrink:0;">📷 `, once in the file) beside the 5.18.66 photo-surface markers;
**RB** (after a rollback) proves the old layout is back *and* the 5.18.66 photo surface is intact — the rollback restores
code only, no data was involved. V5, V3/R2, R4 ("no `partner_api.php` / `StaffApiCsrf.php` installed") and R5 (Release A
→ 5.18.66, 179 files) are as before. 22 checks on a clean deploy. The operator's command is in the header, deploy only;
the rollback is printed at the end of the deploy's own log, as its own command (root docs/44 §16.9).

**4. Rehearsal `scripts/harness/deploy-5.18.67/rehearse.sh`: 103/0 over 16 runs of the script, twice.** Against a clone
of this repo, the real `deploy-hybrid.sh`, a fake container holding 5.18.66 exactly (`git archive 8137912`) with the pilot
*on* and the photo tables created by 5.18.66's own migration run (`present:0:0`), and a stand-in web server for stage V:
**0** controls — 7 files, none new, no migration, no portal/CSRF file; the marker present at the pin and absent at the
base; the header command stands alone (no `--rollback`, no checkout of another commit); **1** NO-GO — a server still on
5.18.65 ("deploy 5.18.66 first", nothing deployed, no backup), a placeholder pin, and **a copy pinned to the branch tip
`9343f60` (parent `7cefd79`) refused before any live read**; **2** the deploy as the operator runs it — PASSED, 22 ok,
every expected line, five backups, every changed file byte-for-byte, **the data digest unchanged** (no table, no setting),
photo tables 2 and pilot tables 8 as before, `partner_api.php`/`StaffApiCsrf.php` absent, the rollback command once and
after the verdict, and the code backup's job page carries the *old* layout (a rollback restores 5.18.66's card exactly);
**3** teeth — the job page reverted to 5.18.66 on the install fails **R1 by name and R6 on `old-photo-layout`**; a changed
Release-A file fails R5 by name; each removed, `--after-only` passes; **4** V3/R2 read the live switch off and on again;
**4e** a live channel planted in `webhook.php` and a planted `partner_api.php` each fail R4 by name; **5** the rollback
(typed `ROLLBACK`) — PASSED, 5.18.66's manifest and card back, the photo surface intact, no data changed; **6** an
R1-blinded copy calls the reverted page installed while the real script fails it (control on the control); **7** the
checkout as found, no weakened copy left. The 5.18.66 rehearsal's 2b (084's first boot) has no counterpart: nothing
applies on first request here.

**5. The operator's command** (deploy only; send back the log file):
`cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.67 && mkdir -p /root/dnb-5.18.67 && bash scripts/deploy-5.18.67.sh 2>&1 | tee /root/dnb-5.18.67/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`
— it refuses unless the container serves `8137912`; `--check` will read "NOT up to date" before and after (the branch tip
is not what is installed — the documented consequence, not a fault).
- **RESULT — DEPLOYED to production 2026-10-03, 20:07 UTC: PASSED, 21 ok / 0 failed / 0 notes.** The run began at
  20:06:43 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves 96857d8"*; the seven files
  were stamped at 20:07:10 UTC. Recorded from the terminal the operator pasted (the script prints no secret); the log
  file stays on the server as `/root/dnb-5.18.67/deploy-20261003T200643Z.log`.
  - **A.** Checkout `4c72a4a`; branch tip `9343f60` (not installed); release commit `96857d8` cut on `8137912`; 7 files
    (7 changed, 0 added, 0 removed), **0 migrations**; **A0** — no partner-portal or CSRF file and no migration in the
    delta. Live `8137912` / 5.18.66. The container's PHP **8.1.34** accepted the one changed server file and the five
    test files. Pilot `on`. **Photo tables `present:3:1`, `uploads/job_photos` holding 3 files** — see below.
  - **Backup** `/root/dnb-5.18.67/backup-20261003T200643Z`: `plugin.sqlite3` 26 MB, one consistent copy, integrity ok,
    **241 tables** (239 at 19:39 — the two 084 tables have been created since); the data directory 126 MB; the installed
    5.18.66 11 MB; the vault. No `dishnet.sqlite` (nothing to copy). `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal without a session 302; no South Sudan contact; **V5** the photo
    viewer without a session 302, `job_photo_upload` without a login 401; **V3** the pilot unchanged (`on` → `on`);
    **V4** no fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 7 files as `96857d8` has them, manifest 5.18.67; R2 `pilot=on`; **R3 084 still installed, its two
    tables present with `3:1` rows, untouched** (3 photo files on disk, never read by the script); R4 the pilot libs, the
    two flag-gated hooks and the Null channel as before, the pilot tables present, no `partner_api.php` /
    `StaffApiCsrf.php` installed; R5 all 179 files from Release A through 5.18.66 intact; **R6 the job page carries the
    new card layout** with the 5.18.66 photo surface intact.
  - **First use of 5.18.66 in production, seen in passing.** Between the 5.18.66 deploy (19:39 UTC) and this run
    (20:06 UTC) production gained **3 photo rows (3 files on disk) and 1 completion with a location** — the row counts
    the before-evidence and R3 read, not something the deploy did. Whose job, and whether it was a trial, the log does
    not say; the 5.18.66 entry's "not yet seen" is therefore *seen in the database*, not yet reviewed on a screen.
  - **Next:** the card on a phone (the width that wrapped before); later `bash scripts/deploy-5.18.67.sh --after-only`.
    The server checkout moved to `4c72a4a` by the pull; `56f8b00` (docs only) arrives with the next one. The standing
    hazard stands: deploy only through a pinned `scripts/deploy-5.18.NN.sh`, never a bare `deploy-hybrid.sh`.

**6. The full plugin suite, on the tree that carries 5.18.66 and 5.18.67** (`4c72a4a`; its plugin tree is `9343f60`'s):
`tests/run.sh` — **264 files, 12,055 passed / 0 failed, exit 0, twice.** Run 2 began while the 5.18.67 files were still
being edited and is not the record (its totals happen to be identical); **run 3, on the final tree, is.** One verdict
line per file, no fatal, no file skipped. Among them: `test_job_photos` 72/0, `test_job_access` 83/0,
`test_job_notifications_day` 50/0 (the byte-for-byte 5.18.51 baseline on Uganda and South Sudan),
`test_staff_jobs_south_sudan` 51/0, `test_api_csrf_guard` 51/0, `test_partner_api` 32/0, `test_dist_isolation` 39/0. Run 1,
during the 5.18.66 build, had been green except for the six version pins that still read 5.18.65 — bumped before the
commit, each re-run green. This is the whole-suite confirmation the 5.18.66 entry above lists only per test.

## 04 Oct — 5.18.68: staff money in the book's own currency (Uganda); the Cashbook shows cash in hand — BUILT, NOT deployed

**Reported by the operator (two screenshots and two exports, 06:36 UTC):** South Sudan's cashbook "is a proper system";
on Uganda "if we give any staff any money then it is not reflecting in that staff", and "I don't want working capital
and bank amount to be shown in the plugin in Uganda — the UGX cash book is correct". Diagnosed from the code and the
exports, then three decisions put to the operator and taken (AskUserQuestion): **cash in hand only** on the Cashbook
page (display only, no data change); **backfill** the five earlier advances to Elisha; and **USD is sometimes handed to
Uganda staff too** — so the UGX fix keeps a USD bag in view and the USD staff bag is scoped as a follow-up.

**1. What was actually wrong — measured.** The chain *Add Entry → auto-link → cash_ins.json → StaffLedgerWriter::onCashIn →
staff_ledger → Staff Cashbooks* existed and worked for South Sudan's literal `'USD'` bag only:
- the auto-link wrote the staff cash-in with `amount = currency === 'USD' ? amount : 0` — **a UGX advance arrived as 0**
  (`includes/post/post_cashbook.php`, two blocks; the same line in `post_field.php` ×3 and `my_account.php`);
- `onCashIn` then returned on `amount <= 0`, and would have labelled the row `USD` anyway (`lib/StaffLedgerWriter.php`);
- `StaffCashPositionService::getUSDBalance()` (14 literal `'USD'` tests), `DualReadCashPosition` (`position($id,'USD')`),
  `staff_cashbooks.php` (`$uL = cur === 'USD'`), `my_account.php` and `wallet.php` **dropped every UGX row**. Elisha's
  eight approved field expenses had reached the staff ledger in UGX and were invisible too. From the export: five
  advances (UGX 650,000, 23 Sep–3 Oct) against UGX 310,328 of expenses — a page that should read about UGX 339,672
  read nothing.
- The Cashbook page's POSITION card (UGX −24,119,205) was the Phase-C account model: money accounts (Cash–Uganda
  +29,137,000, Ecobank −24,922,777 — the statement import brought only card purchases) plus "unassigned rows"
  −28,333,428, which includes the operator's **UGX 29,056,500 "ADJUSTMENT ENTRY" of 12 Sep** that zeroed the running
  balance. The ledger's running balance (UGX 723,072) is right *because* the adjustment cancels the account rows; cash
  rows alone, adjustment excluded, give exactly 723,072 as well. Two arithmetics on one table.

**2. The principle (5.18.57's, applied to the money logic):** the code's "USD" bag is the BOOK's BASE bag. Every "is this
the USD bag?" question became "is this the book's base bag?" — `dn_book_base()` — and every writer that said "amount only
when USD" now says "amount for every non-SSP currency" (SSP alone lives in `ssp_amount`). On South Sudan the base is USD
and the only other currency is SSP, so each change is an identity there.

**3. Files.**
- `includes/post/post_cashbook.php` (the wizard `cb_action=add_entry` and the `action=cashbook_add_entry` auto-links, and
  the staff-payment auto-link), `includes/post/post_field.php` (three field-register writers), `tabs/sales/my_account.php`
  (the staff-pay writer): `!== 'SSP' ? $amount : 0`.
- `lib/StaffLedgerWriter.php::onCashIn` — a `'USD Received'` row is labelled with the cash-in's own currency; missing or
  SSP-marked stays `USD` exactly as before.
- `lib/StaffCashPositionService.php` (`$this->base = dn_book_base(null)`, every comparison and SQL literal),
  `lib/DualReadCashPosition.php` (`position()/allPositions()` asked for the base bag).
- `tabs/accounts/staff_cashbooks.php` — collections carry their currency; `$uL`/`$_allUsd` filter on `$scBaseCode`;
  advances/expenses/transfers carry every non-SSP amount. `tabs/sales/my_account.php` — one `$_mcBase`; the guard, the hero
  figures, the two ledgers, the book view and the labels read the base; **the two currency radios' `value` is the base code**
  (they were literal `USD` under a UGX label — a Uganda staff's own expense form recorded dollars). `tabs/sales/wallet.php`
  — the three USD filters. `lib/NotificationService.php::staffCashReceived` — money in a currency other than the base is
  named by its code ("USD 100.00"), the display symbol belongs to the base.
- `tabs/accounts/cashbook.php` — on a book without SSP the hero is one **CASH IN HAND** card per currency =
  `CashbookService::cashInHand($currency, $project)` (**new**: the same rows and arithmetic as `getEntries()`' running-balance
  streams; accounts, counterparts and the unassigned split play no part). `cron/cashbook_summary.php` — the evening
  WhatsApp summary on such a book says the same figure per currency, then today's P&L, instead of POSITION/accounts/
  unassigned/counterparts. `includes/navigation.php` — the *Opening Balances* strip link is not drawn on the Uganda tenant
  (the screen stays reachable by address; South Sudan's strip unchanged).
- `tools/backfill_staff_cash_ins.php` (**new**) — every cb_ledger OUT in the base currency with a staff-advance category
  (Staff Advance, Commission, SSP Advance; never Salary or an allowance) naming a person: no cash-in with that SR → CREATE;
  a cash-in with amount 0 → FIX; an amount → SKIP already linked; a name fitting no or two staff members → SKIP, never
  guessed. Dry run by default; `--apply` asks for a typed `APPLY` (`--yes` for tests); writes through the live chain's own
  record shape and `onCashIn` (idempotent `CIN-<id>`); dates the created cash-in on the day the money went out; no
  WhatsApp; refuses any book that is not the Uganda tenant with a non-USD base; logs to `activity_log.json`.
- `SAFETY.md` RULE 8 — a clarification: `amount` is in the BOOK's base currency (USD on South Sudan, UGX on Uganda since
  Phase A); the substance — never SSP in `amount` — unchanged. `manifest.json` 5.18.68; the nine version pins.
- Tests: `tests/test_staff_cash_chain.php` (**new**); `tests/test_cashbook_currency.php` — the two assertions that pinned the
  old POSITION hero rewritten to the new truth plus one asserting no account/bank/unassigned figure on the non-SSP hero.

**4. What was deliberately NOT done.** No data change by the deploy (the backfill is its own command, typed); the 12 Sep
adjustment and the 34 bank-import rows stay as they are (the operator chose "cash in hand only"); the account tables and the
Opening Balances screen stay (hidden from the strip, not removed); `tools/bank_statement.php` is left in place but should not
be run again on Uganda; the USD staff bag on Uganda (a second tab like South Sudan's SSP bag) is a follow-up — today a USD
advance reaches the ledger labelled USD and is kept out of the UGX figure; the passbook's `fr_curr=USD` filter and the
`collection`-named ledger category of a cash-in are pre-existing and untouched.

**5. Tests.** `test_staff_cash_chain.php` **73/0**, driven through the REAL web wizard and the real field-expense forms on
sandboxed plugins (fake uCRM, fake Evolution): **A** a UGX 150,000 Staff Advance → cb_ledger OUT, a cash-in carrying UGX
150,000 (not 0), a UGX staff-ledger IN row keyed `CIN-<id>`, the technician's WhatsApp text "UGX 150,000.00"; **B** Staff
Cashbooks for the technician reads UGX 150,000.00 and lists the advance, the landing tiles too, the technician's own My
Cash hero reads UGX 150,000.00 and never says USD, the expense form offers UGX as the base; **C** a UGX 40,000 field
expense submitted and approved → cashbook OUT, ledger OUT, both pages read 110,000.00; **D** a USD 100 advance is its own
bag (cash-in USD, ledger USD, UGX still 110,000, text "USD 100.00"); **E** a Transport Allowance creates no cash-in (the
personal-pay rule, unchanged); **F** the Cashbook page reads "UGX CASH IN HAND · UGX 290,000.00" and "USD CASH IN HAND ·
USD -100.00", no POSITION, no "unassigned rows", no account count, no Opening Balances tab, the other tabs present;
**G South Sudan** — a USD 50 and an SSP 100,000 Staff Advance through the same forms produce cash_ins, staff_ledger,
cb_ledger rows and WhatsApp texts **byte for byte equal to a golden captured from the 5.18.67 tree** (scratchpad
`ss_capture.php`, run on both trees: IDENTICAL), the page keeps its USD BALANCE / SSP BALANCE cards and the Opening
Balances tab, the backfill tool refuses the book (exit 2); **H the backfill** — a planted 5.18.67-style ghost (amount 0,
no ledger row), an advance to a person hired later, and an ambiguous name with no cash-in: the dry run plans FIX / CREATE /
SKIP ambiguous, omits the USD advance, and writes nothing (cash-ins and ledger identical); `--apply --yes` restores UGX
150,000 and its CIN row, creates the late hire's cash-in dated the day the money went out with its ledger row, guesses
nobody for the ambiguous row, states each staff member's UGX in hand, is in the activity log, sent no message; a second
run reads SKIP throughout; **I four weakened copies each caught** (the wizard's amount rule, `onCashIn`'s label, the
Staff Cashbooks filter, the position service's base). Also green: `test_cashbook_currency` 86/0, `test_cashbook_tenant`
26/0, `test_cashbook_accounts` 118/0, `test_cashbook_seeds` 37/0, `test_staff_cashbook_scope` 9/0. PHP 7.4 syntax throughout.
Wider regression, all green: `test_notify_staff_side` 53, `test_notify_staff_controls` 45, `test_job_notifier` 130,
`test_staff_jobs_south_sudan` 51, `test_job_access` 83, `test_job_notifications_day` 50 (the byte-for-byte 5.18.51 baseline
on both countries), `test_currency_sweep` 9, `test_sales_support_tenant` 36, `test_starlink_accounts` 52, `test_dpo_screens` 51.

**6. Deployment — prepared the project's way, NOT run.** Main commits `6c7a4df` (the build), `e66faf8` (a fix the rehearsal
found, below), `eaec5ca`/`6ba8593` (the deploy script and its pin). **Release commit `release/5.18.68` = `d8d2068`, parent
`96857d8` (5.18.67, production since 3 Oct 20:07 UTC)** — the two main commits' plugin changes cherry-picked onto the live
version, the four pins for test files that do not exist at 5.18.67 left out: **23 files, 2 added, 0 migrations, and the hunks
on those 23 files are byte for byte the branch's** (diffed), with none of the undeployed portal/CSRF files (A0's pattern: 0
hits). On the release tree itself: `test_staff_cash_chain` 73/0, `test_cashbook_currency` 86/0, `test_cashbook_tenant` 26/0,
`test_distributor_registry` 59/0, `test_staff_cashbook_scope` 9/0.
- **`scripts/deploy-5.18.68.sh`** (pinned `d8d2068` over `96857d8`), the 5.18.67 script's shape: **A0** refuses a pin whose
  parent is not 5.18.67, or whose delta carries a migration or any partner-portal / CSRF file; **R6** checks the chain's
  markers beside the photo surface (the auto-link's amount rule twice, the ledger writer's label, the position service's
  base, `cashInHand()`, the CASH IN HAND card, no `currencyPositions()` on the page, the strip gate, the evening summary, the
  tool installed); **R7** runs the backfill tool **without `--apply`** inside the container — a dry run on the live data, its
  plan printed (SR, date, category, the name as typed, the staff member matched, amount, action) for the operator to read,
  and the photo tables/files re-read to prove nothing moved; **F** prints the rollback alone after the verdict, and the
  backfill's `--apply` command after that as a **third separate block** (`docker exec -it ucrm php …/tools/
  backfill_staff_cash_ins.php --apply`, typed `APPLY`). Code only; no migration; 22 checks on a clean deploy before R7's two.
- **Rehearsal `scripts/harness/deploy-5.18.68/rehearse.sh`: 134/0 over 17 runs of the script, twice** on the final script,
  against a 5.18.67 base holding a UGX book (`cashbook_base_currency=UGX`) with the pilot *on* and **one unlinked Staff Advance
  seeded** (a staff member the live link never saw): the three NO-GO gates (a 5.18.66 server, a placeholder pin, a copy pinned
  to the branch tip refused before any live read); the deploy as the operator runs it (24 ok; R7 plans *1 to create* for the
  seeded advance and **the data digest of every table is unchanged across the deploy — the dry run wrote nothing**); R1/R6
  teeth (the position service reverted → R1 names it and R6 says `position-service:literal-usd`; the Cashbook page reverted →
  R6 names the missing card and the position cards); R5 teeth; the switch flipped; a live channel and a planted
  `partner_api.php` caught by name; the rollback restores 5.18.67's page and service with the photo surface intact and no
  data change; the R1-blinded copy caught; the rollback printed once after the verdict and the `--apply` command once after
  the rollback.
- **The first rehearsal run found a real defect (132/2):** the dry run had left an **empty `cash_ins` table** behind — the
  store creates a JSON-list table the first time a name is loaded — so the digest moved. The tool now reads the cash-ins only
  where the table exists (`e66faf8`); proved on the rehearsal's seed: no table change after a dry run, `--apply` still writes.
  The other miss was the harness's own stale expectation (the backup is of 5.18.67, not 5.18.66). The release was re-cut
  (`358117d` → `d8d2068`) and the script re-pinned.
- **The operator's commands — three, never pasted together.** (1) The deploy, in the script's header:
  `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.68 && mkdir -p /root/dnb-5.18.68 && bash scripts/deploy-5.18.68.sh 2>&1 | tee /root/dnb-5.18.68/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`
  — it refuses unless the container serves `96857d8`. (2) The rollback, printed by the deploy's log on its own. (3) The
  backfill's `--apply`, printed after it, to run only after reading R7's plan in the same log; it asks for `APPLY`, writes only
  the CREATE/FIX rows listed, sends no message, and reads SKIP throughout on a second run.
- **RESULT — DEPLOYED to production 2026-10-04, 04:35 UTC: PASSED, 23 ok / 0 failed / 0 notes.** The run began at
  04:34:40 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves d8d2068"*; the 23 files were
  stamped at 04:35:10 UTC. Recorded from the terminal the operator pasted (the script prints no secret); the log file
  stays on the server as `/root/dnb-5.18.68/deploy-20261004T043440Z.log`.
  - **A.** Checkout `b8a0483`; branch tip `e66faf8` (not installed); release commit `d8d2068` cut on `96857d8`; 23 files
    (21 changed, 2 added, 0 removed), **0 migrations**; **A0** clean. Live `96857d8` / 5.18.67. The container's PHP
    **8.1.34** accepted all 14 changed server files and the 7 test files. Pilot `on`; photo tables `present:3:1`, 3 files.
  - **Backup** `/root/dnb-5.18.68/backup-20261004T043440Z`: `plugin.sqlite3` 27 MB, one consistent copy, integrity ok,
    241 tables; the data directory 132 MB; the installed 5.18.67 11 MB; the vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V5** 302 / 401; **V3** the pilot
    unchanged (`on` → `on`); **V4** no fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 23 files as `d8d2068` has them, manifest 5.18.68; R2 `pilot=on`; R3 084 still installed, `3:1` rows,
    untouched; R4 the pilot as before, no portal/CSRF file; R5 all 179 files from Release A through 5.18.67 intact; **R6
    the staff-cash chain is in place** beside the photo surface; **R7 the backfill's dry run on the live data: 5
    staff-advance OUT rows in UGX name a person, 8 cash-in records exist — plan: 0 to create, 5 to FIX, 0 skipped**, each
    of the five (CB-66, CB-72, CB-83, CB-90, CB-95; UGX 50,000 + 250,000 + 100,000 + 100,000 + 150,000) matched to the
    one staff member (#4) and each already carrying a cash-in with **amount 0** (cash-ins #1, #2, #6, #7, #8). **That is the
    diagnosis confirmed on production**: the 5.18.67 auto-link did fire for every advance and wrote 0. The photo tables and
    files read exactly as before (`present:3:1`, `files:3`) — the dry run touched no data.
  - **Not yet done:** the backfill's `--apply` (the operator's separate command, after reading R7's plan; result to be
    recorded here), a look at the UGX CASH IN HAND card against the ledger's latest Balance, and
    `bash scripts/deploy-5.18.68.sh --after-only`.
  - **The full plugin suite on the final 5.18.68 tree (`6c7a4df` + `e66faf8`): `tests/run.sh` 265 files, 12,129 passed /
    0 failed, exit 0**, one verdict per file (12,055 before this release + the 73 of the chain test + the currency test's
    one new assertion).

## 04 Oct — 5.18.69: the last three "USD" labels on the Uganda staff cash screens; a staff-records currency tool — DEPLOYED 06:26 UTC (PASSED 23/0/0)

**Reported by the operator (a screenshot and an export of the technician's staff cashbook, after 5.18.68 and before the
backfill's `APPLY`):** the page read **UGX 0.00** with UGX 310,328 out and a row *"USD Received … +UGX 0.00"*; the export
came down as `staff-cashbook-<staff>-USD-2026-09-04-to-2026-10-04.csv` with the columns `Received (USD)` / `Payment (USD)`
and three rows of 25 Sep (Collection, 200,000 / 50,000 / 50,000, approved); *"I have never given USD to this technician,
always UGX — something wrong"*. The `+UGX 0.00` row is the pre-backfill state the 5.18.68 deploy's R7 showed (the five
cash-ins carry amount 0 until `--apply` is typed through; whether `APPLY` was typed is not yet confirmed). The rest is
three more literal `'USD'` sources — measured on the code and on the export, not inferred from the words:

1. **The Manual Entry stamped `'USD'` whatever currency was chosen.** `tabs/accounts/staff_cashbooks.php` read
   `man_currency` and then wrote `'currency' => 'USD'` on every non-SSP entry, so a hand-typed UGX entry left the UGX
   register (5.18.68 reads the base bag) and surfaced as dollars in the export. The three 25 Sep rows are exactly that
   shape: `source = manual_adjustment`, stamped USD, UGX amounts. Read against the main cashbook's export they are the same
   money as the advances **CB-66 (50,000) and CB-72 (250,000)** of those days — 300,000 both ways — typed again by hand on
   the staff page while the advance link wrote 0. The 5.18.68 backfill links those advances to the technician's ledger, so
   **counting both would double the money**: the tool's default repair is VOID, and RELABEL is offered only for a hand entry
   that is the only record of its money. **Void or keep is the operator's call; the deploy repairs nothing by itself.**
   **Verified line by line at 06:25 UTC**, when the operator re-sent the export (byte-identical to the 04:42 copy, so the
   server was still on 5.18.68): CB-72 (24 Sep, 250,000) reads *"Paid for Buying Material and 50K UGx as Advance"* and the
   two 25 Sep rows split exactly so — *"Outdoor ethernet cable roll 305 m"* 200,000 + *"Advance for other expenses"*
   50,000; CB-66 (23 Sep, 50,000) *"Paid Advance Against Installation work"* is the *"6th street installation allowance
   (advance)"* 50,000. The cable itself already sits on the OUT side as field expense EXP-202609-002 (CB-77, 25 Sep,
   190,000, `expense_sync`), so the IN side is the only thing the hand copies add — and the backfill adds it properly.
2. **The stored category `'USD Received'`.** The base-bag cash-in category is stored under South Sudan's name, and the
   Staff Cashbooks page, My Cash and the export printed it as stored. Each now prints it as **`<base> Received`** — "UGX
   Received" on Uganda; the stored value is unchanged (the ledger writer, the position service and the backfill key on it).
3. **The export's tab→currency rule.** `includes/routes.php` (`sc_export=csv`) turned the page's `usd` tab into literal
   USD through `dn_entry_currency`, so the base tab exported the USD bag — empty on Uganda but for the mis-stamped rows —
   under USD headers and a `-USD-` file name. Now an explicit `ssp` tab exports SSP and everything else the **book's**
   base; the file name and the two column headers follow (`Received (UGX)` / `Payment (UGX)`).

**Files** (`c1c2f62`): `tabs/accounts/staff_cashbooks.php` (the stamp through `dn_entry_currency`, the confirmation names
the currency, the label); `includes/routes.php` (the rule, the label, expense and handover rows fall back to the base
instead of literal USD); `tabs/sales/my_account.php` (two labels); `manifest.json` 5.18.69; the nine pins. New:
`tools/staff_records_currency.php`, `tests/test_staff_manual_entry_currency.php`. **No migration, no new table, no uCRM
write, no message, no setting.** South Sudan (base USD): `dn_entry_currency` yields USD for the USD choice, the label is
"USD Received", the rule gives USD — identities, proved on a South Sudan sandbox (section E of the test).

- **`tools/staff_records_currency.php`.** **LIST** (default, read-only): every staff cash record by table and currency
  across `payment_collections`, `cash_ins`, `cash_expenses`, `cash_handovers`, `staff_expenses`, `cash_advances`,
  `staff_transfers`, `staff_ledger` (voided rows included; a non-base, non-SSP stamp is flagged `◄ not the base`), and
  every Manual Entry collection stamped in a non-base currency, listed by id, date, staff member, stamp, amount, status and
  description. **`--void`** (typed `VOID`): the Staff Cashbooks page's own void, record for record — `prev_status`,
  `status = voided`, `voided_by`, `voided_at`, `void_reason`, an `audit_log` entry, the matching `cb_ledger` row by its
  `COL-`/`PAY-` reference where one exists, one `activity_log` entry; nothing deleted, no message. **`--relabel`** (typed
  `RELABEL`): `currency → base` with an `audit_log` entry, the row stays approved. `--yes` for a non-interactive run; both
  flags together, an unknown option, or any book but a Uganda one (tenant `uganda`, base ≠ USD) → exit 2, nothing written.
  It reads a table only where it exists — a LIST creates nothing (the 5.18.68 dry-run lesson, applied before the rehearsal
  this time).
- **`tests/test_staff_manual_entry_currency.php` — 36/0.** A: the accountant's manual UGX 20,000 entry is stamped UGX,
  `source manual_adjustment`, and counts under the UGX tile. B: a UGX 150,000 Staff Advance through the wizard reads **"UGX
  Received"** (never "USD Received"), hero 170,000. C: the `usd` tab's CSV carries `Received (UGX)` / `Payment (UGX)`, the
  manual entry and the advance as "UGX Received". D: a planted USD-stamped manual collection (as the old form wrote it)
  does not count; LIST exits 0, names `payment_collections: USD 1 ◄ not the base` and the candidate, writes nothing; VOID
  voids it the page's way with its stamp untouched and an activity-log line; a second planted row is RELABELLED to UGX and
  then counts (240,000) while the voided one does not; both flags → exit 2. E: South Sudan — a manual USD entry stamped
  USD, the confirmation "Manual USD entry added", the USD and SSP exports unchanged, the tool refuses (exit 2). F: two
  weakened copies caught — the stamp put back to literal `'USD'` (the UGX entry is stamped USD again) and the export's rule
  put back to `dn_entry_currency` (the base tab exports `(USD)` again). Also green on the tree: `test_cashbook_currency`
  86/0, `test_cashbook_tenant` 26/0, `test_staff_cash_chain` 73/0.
- **Release commit `release/5.18.69` = `fec15bc`, parent `d8d2068` (5.18.68, production since 04:35 UTC):** `c1c2f62`'s
  plugin changes applied on the live version, the four pins for test files that do not exist at 5.18.68 left out
  (`test_dist_isolation`, `test_partner_api/_auth/_session`): **11 files, 2 added, 0 migrations, hunks byte for byte the
  branch's** (diffed), 0 partner-portal/CSRF hits. Of the eleven, only `includes/routes.php` differs between d8d2068 and
  the branch tip (the tip carries nine undeployed lines elsewhere in the file); the hunks applied cleanly. On the release
  tree: `test_staff_manual_entry_currency` 36/0, `test_cashbook_currency` 86/0, `test_staff_cash_chain` 73/0,
  `test_distributor_registry` 59/0, `test_cashbook_tenant` 26/0, `test_staff_cashbook_scope` 9/0.
- **`scripts/deploy-5.18.69.sh`** (pinned `fec15bc` over `d8d2068`), the 5.18.68 script's shape: **A0** refuses a pin
  whose parent is not 5.18.68, or whose delta carries a migration or any partner-portal / CSRF file; **R6** checks the
  three fixes beside the photo surface and the 5.18.68 chain (the Manual Entry stamp `=> $manCur`, the page's
  `$scBaseCode.' Received'`, the export's `=== 'ssp' ? 'SSP' : dn_book_base(…)`, My Cash's `$_mcBase.' Received'`, both
  tools installed); **R7** runs the records tool in **LIST** mode inside the container — read-only on the live data, its
  census and its candidates printed for the operator to read (staff names as the panel shows them; no phone number) and
  the photo tables/files re-read to prove nothing moved; **RB** after a rollback checks the literal stamp and the literal
  tab are back and the 5.18.68 chain and card still there; **F** prints the rollback alone after the verdict, and the
  tool's `--void` command after that as a **third separate block**, naming `--relabel` as the alternative. Code only; no
  migration.
- **Rehearsal `scripts/harness/deploy-5.18.69/rehearse.sh`: 129/0 over 18 runs of the script**, against a 5.18.68 base
  holding a UGX book with the pilot *on* and **one Manual Entry collection stamped USD by the old form seeded** (a hand copy
  of an advance, approved — the shape production holds since 25 Sep): the three NO-GO gates (a 5.18.67 server, a placeholder
  pin, a copy pinned to the branch tip refused before any live read); the deploy as the operator runs it (R7's LIST names
  `payment_collections  USD 1 ◄ not the base` and the candidate — USD 50,000.00, approved, as typed — and **the data digest
  of every table is unchanged across the deploy: the LIST wrote nothing, the seeded entry still approved and still stamped
  USD**); R1/R6 teeth (the Staff Cashbooks page reverted → R1 names it and R6 names `manual-entry:stamps-literal-usd` and
  `staff-cashbooks:usd-received-label`; the export reverted → R1 names it and R6 `export:literal-usd-tab`; My Cash reverted →
  `my-cash:usd-received-label`); R5 teeth; the switch flipped; a live channel and a planted `partner_api.php` caught by name;
  the rollback restores 5.18.68's stamp and export with the 5.18.68 chain, card and photo surface intact and no data change;
  the R1-blinded copy caught; the rollback printed once after the verdict and the `--void` command once after the rollback,
  with `--relabel` named once as the alternative. No defect on the first run this time (the 5.18.68 dry-run lesson was
  applied before it). Run 1 on the working copy of the script, run 2 on the committed script (`1e718c3`): **129/0 both times, the same 18 runs**.
- **The operator's commands — three, never pasted together.** (1) The deploy, in the script's header:
  `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.69 && mkdir -p /root/dnb-5.18.69 && bash scripts/deploy-5.18.69.sh 2>&1 | tee /root/dnb-5.18.69/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`
  — it refuses unless the container serves `d8d2068`. (2) The rollback, printed by the deploy's log on its own. (3) The
  tool's `--void` (or `--relabel`), printed after it, to run **only after the operator has read R7's list and decided**;
  it asks for `VOID` / `RELABEL`, touches only the rows the LIST named, sends no message, and on a second run reports
  *nothing to void*. The 5.18.68 `--after-only` is superseded: this deploy's R5 re-reads every file from Release A through
  5.18.68.
- **Still open from 5.18.68:** the backfill's `--apply` log (not received; the screenshot shows the pre-backfill 0.00).
  After it, the technician's page should read about UGX 339,672 (650,000 in, 310,328 out) — plus 300,000 if the three
  hand-typed rows are relabelled rather than voided. **Confirmed on the page 2026-10-04, about 11:11 UTC: UGX 339,672.00
  (650,000 received, 310,328 out) — recorded under 5.18.71.** **Open here:** the operator's answer — void the three 25 Sep
  hand-typed entries (recommended: they duplicate CB-66/CB-72) or keep them (relabel).
- **Follow-ups noted, not built:** a USD staff bag on Uganda (the operator does sometimes hand out USD — a second tab, its
  own instruction); the passbook's `fr_curr=USD` filter; the cashbook's `collection`-named ledger category;
  `tools/bank_statement.php` is not for the Uganda book.
- **RESULT — DEPLOYED to production 2026-10-04, 06:26 UTC: PASSED, 23 ok / 0 failed / 0 notes.** The run began at
  06:26:17 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves fec15bc"*; the 11 files were
  stamped at 06:26:46 UTC. Recorded from the terminal the operator pasted (the script prints no secret); the log file stays
  on the server as `/root/dnb-5.18.69/deploy-20261004T062617Z.log`.
  - **A.** Checkout `f2af7fb`; branch tip `c1c2f62` (not installed); release commit `fec15bc` cut on `d8d2068`; 11 files
    (9 changed, 2 added, 0 removed), **0 migrations**; **A0** clean. Live `d8d2068` / 5.18.68. The container's PHP **8.1.34**
    accepted the 4 changed server files and the 6 test files. Pilot `on`; photo tables `present:3:1`, 3 files.
  - **Backup** `/root/dnb-5.18.69/backup-20261004T062617Z`: `plugin.sqlite3` 27 MB, one consistent copy, integrity ok,
    241 tables; the data directory 132 MB; the installed 5.18.68 11 MB; the vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V5** 302 / 401; **V3** the pilot
    unchanged (`on` → `on`); **V4** no fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 11 files as `fec15bc` has them, manifest 5.18.69; R2 `pilot=on`; R3 084 still installed, `3:1` rows,
    untouched; R4 the pilot as before, no portal/CSRF file; R5 all 190 files from Release A through 5.18.68 intact; **R6
    the three fixes are in place** beside the 5.18.68 chain and the photo surface. **R7 — the records tool's LIST on the
    live data, read-only — corrects §1's diagnosis of WHICH form wrote the three rows:** `payment_collections UGX 1 ·
    cash_ins UGX 5 · USD 3 ◄ not the base · staff_expenses UGX 8 · staff_ledger UGX 13`, and **Manual Entry collections
    stamped in a currency other than UGX: 0.** The three "USD" rows the operator exported are **`cash_ins` records**, not
    Manual Entry collections — the export prints a cash-in's own category, and theirs is `Collection`. The tool counts and
    flags them, but its repair covers only `payment_collections` rows with `source = manual_adjustment`, so **it cannot void
    them.** What they ARE (hand copies of CB-66 + CB-72, 300,000, UGX wearing a USD label) stands as verified above; what
    wrote them was a cash-in form, not the Manual Entry. The photo tables and files read exactly as before (`present:3:1`,
    `files:3`) — the LIST touched no data.
  - **The operator then ran the tool's `--void` command** (its own command, after the deploy, ~06:30 UTC): the same census,
    *0 (0 not yet voided)*, then **"Nothing to VOID: every candidate is already voided."** Nothing was written — correct —
    but that sentence is **wrong for zero candidates**: nothing was voided because nothing was in scope. Two tool defects for
    5.18.70: that message, and that a table which exists but is empty (`cash_expenses`, `cash_handovers`, `cash_advances`,
    `staff_transfers` on production) prints nothing instead of `0`.
  - **Read off the census, to be confirmed on the page:** `staff_ledger UGX 13` = the technician's 8 approved field expenses
    + 5 cash-in rows — rows that exist only if the 5.18.68 backfill's `APPLY` ran, since 5.18.67's `onCashIn` wrote no row
    for an amount of 0. **The APPLY log was never sent; the ledger count says it was applied.** `cash_ins UGX 5` are those
    five, `USD 3` the hand-typed rows.
  - **Not yet done:** 5.18.70 — the tool extended to `cash_ins` (LIST them by category; VOID the page's `void_cash_in` way;
    RELABEL), the two wording defects, and a check that no form on the Uganda book can still stamp a cash-in `USD`; then the
    operator's VOID through it; `bash scripts/deploy-5.18.69.sh --after-only`.
- **The full plugin suite on the final 5.18.69 tree (`c1c2f62`, the plugin files of `1e718c3`): `tests/run.sh` 266 files,
  12,165 passed / 0 failed, exit 0**, one verdict per file — 12,129 before this release + the 36 of the manual-entry test,
  exactly. 26 minutes, run alongside the second rehearsal without a flake.

## 04 Oct — 5.18.70: the Field Register speaks the book's base; the records tool covers cash-ins — DEPLOYED 07:12 UTC (PASSED 23/0/0)

**Why.** The 5.18.69 deploy's R7 census (06:26 UTC) placed the technician's three "USD" rows in **`cash_ins`**, not in the
Manual Entry collections 5.18.69's tool repairs, and the operator's `--void` run found *nothing in scope* (and said "already
voided" — wrong wording, fixed here). Traced from the export's own column (it prints a cash-in's category; theirs is
`Collection`) to the one writer that stamps that category: **the Field Register page** (`tabs/sales/wallet.php`,
`action=log_cash_in` → `includes/post/post_field.php`). Its base pill is labelled with the book's base, but its JavaScript's
token for that pill is the literal `'USD'`, and every form on the page copied the token into its hidden `currency` field —
cash-ins (`fr3fInCurrency`, twice), expenses (`fr3fCurrency`, five times), the handover and the advance (`_fr3Curr`).
`dn_entry_currency()` then passed `USD` through, because the Uganda book lists `UGX,USD` and USD is a legitimate second bag.
**So the page still stamped USD on 5.18.69**, and would have gone on doing so. The same literal drove that page's filter
whitelist, its two sums, its pending-row label, its filter button, and the Field Register CSV export in `routes.php`.

**What changed** (`c3f9bac` on the branch, release `cba7faf` on `fec15bc`):
- `tabs/sales/wallet.php` — `var _fr3Base = <?= json_encode(dn_book_base($config)) ?>` next to the pill token, and every
  submission for the base pill sends it (`_fr3Base`, or `isSsp ? 'SSP' : _fr3Base`, or `_fr3Curr === 'SSP' ? 'SSP' :
  _fr3Base`); server-side `$_frBase` for the filter whitelist, the collection rows' currency (their own stamp, the base when
  they carry none), the exchange-row filter, the expense/handover/advance fallbacks, the two sums, the pending label and the
  filter button. The pill token `'USD'` itself stays — it is a UI name, not data — and the exchange leg's dollar side stays
  literal (South Sudan only).
- `includes/routes.php` — the Field Register CSV export: collections and handovers carry their own stamp (the base when
  none), the filter compares to it; the staff export of 5.18.69 is untouched.
- `tools/staff_records_currency.php` (5.18.70) — candidates are the Manual Entry collections **and every cash-in** stamped
  in a non-base, non-SSP currency, listed with source (`collection #n` / `cash-in #n`), date, staff member, category, stamp,
  amount, status, description. **VOID** of a cash-in is the Staff Cashbooks page's own `void_cash_in`, field for field
  (`prev_status`, `status = voided`, `voided_by`, `voided_at`, `void_reason`), the page's activity line (`void_cash_in`), then
  `StaffLedgerWriter::onCashInVoided` (and the `CINO-` key for an OUT row). **RELABEL** sets the record's currency to the
  base with an `audit_log` entry and, where a live `staff_ledger` row exists for it, that row's currency — the tool's one
  direct ledger write, reported by key. Zero candidates now read *"no Manual Entry collection and no cash-in is stamped in a
  currency other than UGX"*; a table that exists but is empty prints `0`. Still Uganda-only (exit 2 elsewhere), still
  read-only without a flag, still typed `VOID` / `RELABEL`.
- `manifest.json` 5.18.70; the nine pins. **No migration, no new table, no uCRM write, no message, no setting.**

**Tests.**
- `tests/test_field_cash_in_currency.php` — **48/0.** A: the page tells its JavaScript `_fr3Base = "UGX"`; both cash-in
  submissions, the five expense submissions and the two handover/advance submissions send the base; no literal submission
  left; the filter button links `fr_curr=UGX`. B: the technician's cash-in, posted with what the page now submits, is stamped
  UGX, Collection, approved; the wallet's Collections line and the Staff Cashbooks UGX tile both read 20,000. C: production's
  shape planted — three USD-stamped Collection cash-ins and one `USD Received` with a live ledger row: the wallet's
  Collections line counts the three (320,000: *the label lied and the figure followed*) while the UGX tile does not (the two
  screens disagreed by 300,000); LIST exits 0, reads `cash_ins UGX 1 · USD 4 ◄ not the base`, prints `0` for an empty table,
  lists each cash-in with category, stamp, amount, status and description, writes nothing. D: VOID names each row, voids
  them the page's way (stamp untouched, nothing deleted), voids the ledger row `CIN-n`, writes four `void_cash_in` activity
  lines and its summary; the wallet is back to 20,000 and agrees with the tile; LIST reads *4 (0 not yet voided)*; a second
  VOID says "already voided". E: with nothing in scope VOID says so and exits 0; RELABEL relabels the record and the live
  ledger row, and both then count in the UGX tile (100,000). F: South Sudan — `_fr3Base = "USD"`, the pill submits USD and
  an SSP Received submits SSP exactly as before, the tool refuses. G: three weakened copies caught — the Collection
  submission sent as the literal again; the tool blind to cash-ins; VOID leaving the ledger row live.
- `tests/test_staff_manual_entry_currency.php` 36/0 (two assertions follow the tool's new listing and version),
  `test_cashbook_currency` 86/0, `test_cashbook_tenant` 26/0, `test_staff_cash_chain` 73/0.
- **Release commit `release/5.18.70` = `cba7faf`, parent `fec15bc` (5.18.69, production since 06:26 UTC):** the
  branch commit's plugin changes applied on the live version, the four pins for files absent at 5.18.69 left out:
  11 files, 1 added, 0 migrations, hunks byte for byte the branch's (diffed), 0 partner-portal/CSRF hits.
- **`scripts/deploy-5.18.70.sh`** (pinned `cba7faf` over `fec15bc`), the 5.18.69 script's shape: A0 refuses a
  pin whose parent is not 5.18.69 or a delta carrying a migration or any partner-portal / CSRF file; **R6** checks the base
  token, the two base submissions, no literal submission, the export's base and the 5.18.70 tool, beside the 5.18.69 fixes,
  the 5.18.68 chain and the photo surface; **R7** runs the tool in LIST mode inside the container — read-only — **which on
  production will name the three cash-ins**, each with its category, stamp, amount and description, for the operator to read
  before typing VOID; RB after a rollback checks the token and the 5.18.70 tool are gone and the 5.18.69 fixes still there;
  F prints the rollback alone, then the tool's `--void` command as a third block, naming `--relabel` as the alternative.
- **Rehearsal `scripts/harness/deploy-5.18.70/rehearse.sh`: 129/0 over 18 runs of the script**, against a 5.18.69 base
  holding a UGX book with the pilot *on* and **the three cash-ins the old pill stamped USD seeded — production's shape**
  (Collection, 200,000 / 50,000 / 50,000, approved, 25 Sep): the three NO-GO gates (a 5.18.68 server, a placeholder pin, a
  copy pinned to the branch tip refused before any live read); the deploy as the operator runs it (R7's LIST reads
  `cash_ins USD 3 ◄ not the base` and names each cash-in with its category, stamp, amount, status and description, and
  **the data digest of every table is unchanged across the deploy: the LIST wrote nothing, the three still approved and
  still stamped USD**); R1/R6 teeth (the Field Register reverted → R1 names it and R6 names `field-register:no-base-token`
  and `field-register:literal-usd-submit`; the tool reverted → R1 and R6 `records-tool:not-5.18.70`; the export reverted →
  `export:literal-usd-collection`); R5 teeth; the switch flipped; a live channel and a planted `partner_api.php` caught by
  name; the rollback restores 5.18.69's pill and tool with the 5.18.69 and 5.18.68 fixes and the photo surface intact and
  no data change; the R1-blinded copy caught; the rollback printed once after the verdict and the `--void` command once
  after the rollback, with `--relabel` named once. No defect on the first run. Run 1 on the working copy of the script;
  run 2 on the committed script (`8992574`): **129/0 both times, the same 18 runs**.
- **The operator's commands — three, never pasted together.** (1) The deploy, in the script's header:
  `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.70 && mkdir -p /root/dnb-5.18.70 && bash scripts/deploy-5.18.70.sh 2>&1 | tee /root/dnb-5.18.70/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`
  — it refuses unless the container serves `fec15bc`. (2) The rollback, printed by the deploy's log on its own. (3) The
  tool's `--void` (or `--relabel`), printed after it, **this time with the three cash-ins in scope**: it asks for `VOID`,
  voids only the rows the LIST named, sends no message.
- **Still open:** the 5.18.68 backfill's `APPLY` log (the ledger count says it ran — confirm on the page); the operator's
  void/keep answer (void recommended: CB-66 + CB-72 are the same money).
- **Two suite files corrected on the branch after the deploy (`8cde7ca`, tests only, no release):** the first full-suite run
  on the 5.18.70 tree (06:59–07:2x UTC, a Sunday) reported `test_notify_evo_retry.php` 20/3 and `test_sales_support_tenant.php`
  35/1. The first is **the clock, not the release**: its `er_window_zone()` hunted a zone that is now a non-Sunday 09:00–18:00
  within UTC−11…+12, although `FollowUpPolicy` opens 08:00–20:00 and real offsets run UTC−12…+14; on a Sunday between ~07:00
  and ~19:00 UTC it found none, fell back to UTC, the follow-up sender held the draft for the window, and three assertions
  read a broken sender (the 5.18.69 run at 05:22 UTC still had Saturday 18:22 at UTC−11). It now reads the policy's own hours
  and the real offsets, and when no zone on Earth is inside the window it **skips** the three checks with that reason, counted
  apart — never a failure; 23/0 at 07:23 UTC (window zone UTC−12, Saturday 19:23). The second is **a pin on text 5.18.70
  rewrote**: the wallet's filter gate `? ['USD','SSP'] : ['USD']` is now `? [$_frBase, 'SSP'] : [$_frBase], true` — the same
  gate, the base by its own code; the pin follows and a second one asserts the literal is gone; 37/0. **Rule, binding:** a
  test must not depend on the hour it runs at; where the product has a window, the test controls the clock or skips with
  the reason.
- **The full plugin suite on the final 5.18.70 tree (`5518eb3`; plugin files of `c3f9bac` + the two test corrections of
  `8cde7ca`): `tests/run.sh` 267 files, 12,214 passed / 0 failed, nothing skipped, exit 0**, one verdict per file — the
  5.18.69 total of 12,165 + the 48 of the Field Register test + the one assertion the tenant test gained, exactly. 27
  minutes, 07:26–07:53 UTC; the retry test ran its window checks in full (zone UTC−12, Saturday evening there). The first
  run on the same plugin code (06:59–07:2x UTC) had read 2 files red for the two reasons above, both in the tests.
- **RESULT — DEPLOYED to production 2026-10-04, 07:12 UTC: PASSED, 23 ok / 0 failed / 0 notes.** The run began at
  07:12:45 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves cba7faf"*; the 11 files were
  stamped at 07:13:19 UTC. Recorded from the terminal the operator pasted (the script prints no secret); the log file stays
  on the server as `/root/dnb-5.18.70/deploy-20261004T071245Z.log`.
  - **A.** Checkout `4d19f49`; branch tip `c3f9bac` (not installed); release commit `cba7faf` cut on `fec15bc`; 11 files
    (10 changed, 1 added, 0 removed), **0 migrations**; **A0** clean. Live `fec15bc` / 5.18.69. PHP **8.1.34** accepted the
    3 changed server files and the 7 test files. Pilot `on`; photo tables `present:3:1`, 3 files.
  - **Backup** `/root/dnb-5.18.70/backup-20261004T071245Z`: `plugin.sqlite3` 27 MB, one consistent copy, integrity ok,
    241 tables; the data directory 132 MB; the installed 5.18.69 11 MB; the vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V5** 302 / 401; **V3** the pilot
    unchanged (`on` → `on`); **V4** no fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 11 files as `cba7faf` has them, manifest 5.18.70; R2 `pilot=on`; R3 084 still installed, `3:1` rows,
    untouched; R4 the pilot as before, no portal/CSRF file; R5 all 192 files from Release A through 5.18.69 intact; **R6
    the Field Register's base token, both base submissions, no literal submission, the export's base and the 5.18.70 tool**
    beside the 5.18.69 fixes, the 5.18.68 chain and the photo surface. **R7 — the records tool's LIST on the live data,
    read-only:** `payment_collections UGX 1 · cash_ins UGX 5 · USD 3 ◄ not the base · cash_expenses 0 · cash_handovers 0 ·
    staff_expenses UGX 8 · cash_advances 0 · staff_transfers 0 · staff_ledger UGX 13` (the four empty tables now read `0`),
    and **the three candidates, named: cash-in #3 (Collection, USD, 200,000.00, 25 Sep, "Outdoor ethernet cable roll
    305 m"), #4 (50,000.00, "Advance for other expenses"), #5 (50,000.00, "6th street installation allowance
    (advance )")**, all approved, all the technician's (#4). The photo tables and files read exactly as before
    (`present:3:1`, `files:3`) — the LIST touched no data.
  - **The operator then ran the tool's `--void` command** (its own command, after the deploy): the same census, the three
    rows listed, then the prompt *"Type VOID to void the 3 row(s) above, anything else to stop:"* — **the paste ends at
    the prompt; whether VOID was typed is not yet known.** Nothing is written until it is.
  - **Not yet done:** the typed `VOID` and its records log (expected: three `cash-in #n voided (USD …, Collection, …)`
    lines, no ledger row to void since `Collection` cash-ins have none, four activity lines); the technician's My Wallet
    *Collections* line afterwards (it counted the three — 300,000 — whatever their stamp); the 5.18.68 backfill's result on
    the Staff Cashbooks page (about UGX 339,672); `bash scripts/deploy-5.18.70.sh --after-only`.

## 04 Oct — 5.18.71: the landing page shows cash in hand, not the account position; money held by staff — DEPLOYED 10:59 UTC (PASSED 23/0/0)

**Reported by the operator (two screenshots on v5.18.70, 11:45 Kampala, no words):** the plugin's landing page — the
accounts dashboard, the admin's default tab — with its hero **"CASH POSITION — PER CURRENCY: UGX −24,119,205.00 / USD
−139.37"** and the account tiles (Ecobank Uganda UGX −24,922,777, Cash Uganda 29,137,000, Airtel 0, MTN 0, Ecobank USD
−1,039.37, Cash USD 0), beside the Cashbook page's card **UGX CASH IN HAND 723,072.00 / USD 10,871.37**. The 5.18.68
decision ("I don't want working capital and bank amount to be shown in the plugin in Uganda — cash in hand only") had been
applied to the Cashbook page; the landing hero still drew `currencyPositions()`, the Phase-C account model, on a book
without SSP (`tabs/accounts/accounts_dashboard.php`: `$_dashSSP ? [] : $cbDash->currencyPositions()`), while South Sudan's
branch of the same hero draws the ledger's running balance per project ("Total Cash Position" with Fiber & Starlink /
DishNet 4G / BlueCARD chips). Two more things on that page, read from the code: `getBothBalances()` is hard-coded to the
**USD** stream (`getBalanceByCurrency($project, 'USD')`), so the Money Locations office figure on a UGX book was the dollar
bag; and Money Locations lists staff by *collection exposure* (the `staff_cash_position` view), which reads 0 for a
technician who only holds an advance — so the page said *"All cash is in office — no field holdings"* while the technician
held about UGX 339,672.

**What changed** (`72de8be` on the branch, release `b350192` on `cba7faf`), all on a book without
SSP, South Sudan's branch untouched:
- **The hero reads "Cash in hand — per currency"**: `CashbookService::cashInHand()` per book currency — the Cashbook card's
  own figure (UGX 723,072.00 / USD 10,871.37 today) — and the base per project in the three chips (Fiber & Starlink /
  DishNet 4G / BlueCARD), the shape South Sudan's hero has. `currencyPositions()` is not called on the page any more; no
  bank balance, no unassigned stream, no "position".
- **Money Locations**: the office figure is the base cash in hand (not the literal-USD project balances); the block lists
  **money held by staff** — `StaffCashPositionService::getUSDBalance()` per active non-admin staff, exactly the base
  balance Staff Cashbooks shows (advances and collections received, net of expenses and handovers), each row linking to
  that person's Staff Cashbook; a "With staff" total and a header badge; the empty line reads *"nothing held by staff"*.
  The **Field Cash KPI and its "collectors" count are unchanged** — collection exposure is a different question.
- **`tools/cash_in_hand.php`** (new, READ-ONLY): prints the ledger's running balance per currency and the base per project —
  what the hero shows — for the operator to read beside the screen and for the deploy's R7.
- `manifest.json` 5.18.71; the nine pins. **No migration, no new table, no uCRM write, no message, no setting.**
- Not changed, recorded: `getBothBalances()`'s literal USD (used by the South Sudan hero, the API balances and the evening
  summary's SSP branch) — identity on South Sudan, a latent base-currency item elsewhere; the Cashbook card's USD figure
  (10,871.37) is the USD stream's running balance including bank rows, as 5.18.68 defined it.

**Tests.**
- `tests/test_accounts_dashboard_cash.php` — **30/0.** A: an empty Uganda book — the hero "Cash in hand — per currency",
  UGX 0.00, USD 0.00 beneath, three project chips UGX 0.00, no account position and no South Sudan total, Money Locations
  "nothing held by staff", office UGX 0.00. B: a UGX 1,000,000 receipt and a UGX 150,000 Staff Advance to the technician
  through the real Add Entry wizard — the hero reads UGX 850,000.00, the Fiber & Starlink chip 850,000.00 and the others
  0.00, the USD line untouched, the office UGX 850,000.00, the technician listed holding UGX 150,000.00 with the "With
  staff" total and badge, the Field Cash KPI still UGX 0 / 0 collectors, and **the Cashbook page's card reads the same
  850,000.00**. C: the technician logs a UGX 40,000 expense on the Field Register — the held figure follows Staff
  Cashbooks, the hero does not move. D: South Sudan — "Total Cash Position", the three chips and the exposure list's own
  empty line, unchanged. E: three weakened copies caught — the hero drawing the account position again; the office figure
  reading the literal USD stream again (UGX 0.00 against a 1,000,000 receipt); the held-by-staff list emptied.
- `test_cashbook_currency` 86/0, `test_cashbook_tenant` 26/0, `test_sales_support_tenant` 37/0, `test_staff_cash_chain` 73/0.
- **Release commit `release/5.18.71` = `b350192`, parent `cba7faf` (5.18.70, production since 07:12 UTC):**
  the branch commit's plugin changes applied on the live version, the four pins for files absent at 5.18.70 left out:
  9 files, 2 added, 0 migrations, hunks byte for byte the branch's (diffed), 0 partner-portal/CSRF hits.
- **`scripts/deploy-5.18.71.sh`** (pinned `b350192` over `cba7faf`), the 5.18.70 script's shape: A0 refuses a
  pin whose parent is not 5.18.70 or a delta carrying a migration or any partner-portal / CSRF file; **R6** checks the hero
  label, no `currencyPositions()` call, the held-by-staff list and the tool, beside the 5.18.69/5.18.70 fixes, the 5.18.68
  chain and the photo surface; **R7** runs the read-only cash-in-hand tool inside the container and prints the live book's
  figures — what the hero will show; RB after a rollback checks the hero is gone and the 5.18.69/5.18.70 fixes still there;
  F prints the rollback alone after the verdict and **no repair command — this release has none**.
- **Rehearsal `scripts/harness/deploy-5.18.71/rehearse.sh`: 119/0 over 17 runs of the script**, against a 5.18.70 base
  holding a UGX book with the pilot *on*, a UGX 1,000,000 receipt and a UGX 150,000 Staff Advance seeded (cash in hand
  850,000): the three NO-GO gates (a 5.18.69 server, a placeholder pin, a copy pinned to the branch tip refused before any
  live read); the deploy as the operator runs it (**R7 reads `UGX CASH IN HAND 850,000.00 · USD CASH IN HAND 0.00` and
  the base per project — Fiber & Starlink 850,000.00, the others 0.00 — and the data digest of every table is unchanged
  across the deploy: the tool wrote nothing**); R1/R6 teeth (the dashboard reverted → R1 names it and R6 names
  `dashboard:no-cash-in-hand-hero`, `dashboard:account-position-still-drawn`, `dashboard:no-held-by-staff`; the tool
  removed → R1, R6 and R7 each name it); R5 teeth; the switch flipped; a live channel and a planted `partner_api.php`
  caught by name; the rollback restores 5.18.70's dashboard with the 5.18.70, 5.18.69 and 5.18.68 fixes and the photo
  surface intact and no data change; the R1-blinded copy caught; the rollback printed once after the verdict and **no
  repair command anywhere in the log**. No defect on the first run. Run 1 on the working copy of the script; run 2 on the
  committed script (`91edb18`): **119/0 both times, the same 17 runs**.
- **The operator's commands — two, never pasted together.** (1) The deploy, in the script's header:
  `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.71 && mkdir -p /root/dnb-5.18.71 && bash scripts/deploy-5.18.71.sh 2>&1 | tee /root/dnb-5.18.71/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`
  — it refuses unless the container serves `cba7faf`. (2) The rollback, printed by the deploy's log on its own.
- **Still open:** the 5.18.70 VOID (the operator's terminal stopped at the prompt; the records log was not received); the
  Staff Cashbooks UGX balance confirming the 5.18.68 backfill.
- **The full plugin suite on the final 5.18.71 tree (`72de8be`'s plugin files, at `437a93d`): `tests/run.sh` 268 files,
  12,241 passed / 0 failed, exit 0**, one verdict per file — the 5.18.70 total of 12,214 + the 30 of the dashboard test −
  the 3 checks `test_notify_evo_retry.php` **skipped as designed** at 09:17 UTC on a Sunday (no zone on Earth inside the
  follow-up sending window; each skip printed with that reason, counted apart, never a failure). 27 minutes, 09:04–09:31
  UTC, alongside the second rehearsal without a flake.
- **RESULT — DEPLOYED to production 2026-10-04, 10:59 UTC: PASSED, 23 ok / 0 failed / 0 notes.** The run began at
  10:59:05 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves b350192"*; the 9 files were
  stamped at 10:59:34 UTC. Recorded from the terminal the operator pasted (the script prints no secret); the log file stays
  on the server as `/root/dnb-5.18.71/deploy-20261004T105905Z.log`.
  - **A.** Checkout `7fc1075`; branch tip `72de8be` (not installed); release commit `b350192` cut on `cba7faf`; 9 files
    (7 changed, 2 added, 0 removed), **0 migrations**; **A0** clean. Live `cba7faf` / 5.18.70. PHP **8.1.34** accepted the
    2 changed server files and the 6 test files. Pilot `on`; photo tables `present:3:1`, 3 files.
  - **Backup** `/root/dnb-5.18.71/backup-20261004T105905Z`: `plugin.sqlite3` 27 MB, one consistent copy, integrity ok,
    241 tables; the data directory 133 MB; the installed 5.18.70 11 MB; the vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V5** 302 / 401; **V3** the pilot
    unchanged (`on` → `on`); **V4** no fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 9 files as `b350192` has them, manifest 5.18.71; R2 `pilot=on`; R3 084 still installed, `3:1` rows,
    untouched; R4 the pilot as before, no portal/CSRF file; R5 all 193 files from Release A through 5.18.70 intact; **R6
    the cash-in-hand hero, no account position, the held-by-staff list and the tool are in place** beside the earlier
    fixes and the photo surface. **R7 — cash in hand on the live book, read-only: `UGX CASH IN HAND 723,072.00 · USD CASH
    IN HAND 10,871.37`; Fiber & Starlink UGX 723,072.00, DishNet 4G 0.00, BlueCARD 0.00** — exactly the Cashbook card's
    figures of the morning's screenshot, now also the landing hero's. The photo tables and files read exactly as before
    (`present:3:1`, `files:3`) — the tool touched no data.
  - **Seen by the operator — 2026-10-04, 14:11 on the operator's screen (about 11:11 UTC; two screenshots of v5.18.71,
    no words):**
    - **The technician's Staff Cashbooks page reads UGX 339,672.00** — *"Cash still with staff"*, **▲ UGX 650,000.00
      received · ▼ UGX 310,328.00 out**; the tiles *UGX collected 650,000.00*, *Handed over 0.00*, *Cash with staff
      339,672.00*, *Wallet balance 0.00*; the first row is the 03 Oct Staff Advance of UGX 150,000.00, IN, *UGX Received*,
      approved. **That is the figure predicted since 5.18.68 (650,000 − 310,328), so the 5.18.68 backfill's `APPLY` ran**:
      the `+UGX 0.00` row of the 5.18.69 screenshot is gone. The 5.18.69 deploy's ledger census (`staff_ledger UGX 13`)
      had said as much; the page now says it. The backfill's own log was never received; the page is the outcome.
    - **The landing page hero reads *Cash in hand — per currency*: UGX 723,072.00 · USD 10,871.37; Fiber & Starlink
      UGX 723,072.00 · DishNet 4G UGX 0.00 · BlueCARD UGX 0.00** — R7's figures, and the Cashbook card's. No account
      tiles, no negative position. *Today · 04 Oct UGX 0.00 · 0 payments · 0 new KYC*; the KPI row *Agent float UGX 0
      (9 agents) · Field cash UGX 0 (0 collectors) · MTH recharge UGX 0 · KYC month 0* as before. The screenshot ends
      above **Money Locations**, so the held-by-staff list itself has not been seen in a browser; the figure it lists for
      the technician comes from the same balance call the Staff Cashbooks hero shows, 339,672.00.
    - **Two wording items noticed on the Staff Cashbooks page, not changed:** the tile says *UGX collected* and the
      balance says *Needs handover* — South Sudan's collections wording, while on Uganda this staff member's inflow is
      advances to be spent and accounted for, not collections to hand over. The figures are right; the words are a later
      instruction.
  - **Not yet done:** the 5.18.70 VOID's records log (still not received — the operator's terminal had stopped at the
    prompt; if VOID was not typed, the three 25 Sep hand copies of CB-66/CB-72 still stand as `USD` cash-ins #3, #4, #5
    and the technician's My Wallet *Collections* line still counts their 300,000); Money Locations seen in a browser
    (scroll down on the landing page); `bash scripts/deploy-5.18.71.sh --after-only`.

## 04 Oct — 5.18.72: the Staff Cashbooks tiles on Uganda say what the money is — "received · Advances & collections" and "Still to account for" — DEPLOYED 11:56 UTC (PASSED 23/0/0)

**Reported by the operator (14:1x Kampala, after the 5.18.71 screenshots):** *"fix the collected and handover wording for
uganda"*. On the technician's Staff Cashbooks page (UGX 339,672.00 held: 650,000 received, 310,328 out) the tiles read
**"UGX COLLECTED 650,000.00"** and **"CASH WITH STAFF 339,672.00 — Needs handover"**, beside *"Handed over 0.00 — Given to
accounts"*. That is South Sudan's collections wording, written when every staff member with a bag was a field collector who
collects payments and hands them over to accounts. On Uganda this staff member's inflow is **advances** (and any
collections) to be spent on approved expenses and accounted for; the hero's own pills already said *"received"* / *"out"*,
and the row category reads *"UGX Received"* since 5.18.69. Read from the code (`tabs/accounts/staff_cashbooks.php`, the
selected-staff view): the first tile is `scM($uIn)` — every base-bag IN row (collections, cash-ins, advances, transfers in)
— labelled `<base> collected` on every book; the third tile's line is `'Needs handover'` whenever the position is above
zero. Neither label is gated on the book.

**What changed** (`008e74d` on the branch, release `88d8442` on `b350192`), on a book without SSP only — wording, no figure:
- **The first tile reads "UGX received"** with a new sub-line **"Advances & collections"** (the figure is unchanged: every
  base-bag IN row, as before).
- **The "Cash with staff" line reads "Still to account for"** while a balance is held; *"All settled ✓"* when it is not,
  as before.
- **Unchanged:** *"Handed over · Given to accounts"* (a handover is still cash given back to accounts — the staff member's
  own button says *"Submit cash to office"*), the hero (*"⚠ Cash still with staff"* / *"Cash settled"*), its pills, the
  Wallet tile, every figure, the list page, the CSV export.
- **South Sudan is the same bytes.** The two PHP tags that gate the tile sit at column 0, so the `collected` branch emits
  exactly what it did; the third tile's line is a one-line ternary on `$scSSP`. **Proved**, not assumed: on a South Sudan
  sandbox the selected-staff page from the hero to the currency tabs, rendered by the committed 5.18.71 file and by the
  5.18.72 file on the same data, is **byte-identical — 1,256 bytes on the USD tab, 1,242 on the SSP tab** (control: the old
  block carries the `collected` tile).
- `manifest.json` 5.18.72; the nine pins. **No migration, no new table, no uCRM write, no message, no setting, nothing
  written to any record.**
- Noted, not changed: the My Cash page a Uganda technician sees (*"Advances · Collected · Expenses · Handovers"* over the
  collection exposure, *"You owe company"* when exposure is above zero) uses *"Collected"* for actual collections, which is
  right; its exposure-based hero is a different question (5.18.68's note that `cash_exposure` reads 0 for an advance
  holder).

**Proofs.**
- `tests/test_staff_cashbook_wording.php` (new): **23/0**. A: Uganda — a Staff Advance through the real wizard; the first
  tile asserted **byte for byte** (`UGX received` / `UGX 150,000.00` / `Advances &amp; collections`, four-space indent,
  the tile's own closing tag); no tile says *collected*; *"Still to account for"* exactly once and *"Needs handover"*
  nowhere; the red Cash-with-staff tile; *"Handed over · Given to accounts"*, the hero line, the pills and the Wallet tile
  unchanged; a staff member holding nothing reads *"All settled ✓"* / *"Cash settled"*. B: South Sudan — the old markup
  byte for byte on the USD tab (`USD collected`, the figure, no sub-line), *"Needs handover"*, none of the Uganda words,
  `SSP collected` on the SSP tab. C: **three weakened copies, each caught** — the tile reverted to *collected*, the line
  reverted to *Needs handover*, and the South Sudan branch disabled (caught by the South Sudan control).
- The two tests that pinned the old tile — `test_staff_manual_entry_currency` (3 places) and `test_field_cash_in_currency`
  (5) — now anchor on the tile markup and the new label: **36/0** and **48/0**. **A regex that had matched the label by
  word alone would have matched the hero's "received" pill**; the anchor is the tile's own class.
- Neighbours, unchanged: `test_accounts_dashboard_cash` 30/0 · `test_staff_cash_chain` 73/0 · `test_staff_cashbook_scope`
  9/0 · `test_sales_support_tenant` 37/0 · `test_cashbook_currency` 86/0 · `test_cashbook_tenant` 26/0.
- **The full plugin suite on the final 5.18.72 tree** (`008e74d`'s plugin files, run from `c9377b4` while the deploy
  went out): **`tests/run.sh` 269 files, 12,264 passed, 0 failed, 3 skipped** (the three clock-bound checks of
  `test_notify_evo_retry`, skipped by design on a Sunday afternoon UTC, as at 5.18.71). Was 268 / 12,241 at 5.18.71: one
  file and 23 assertions more, the new wording test.

**Release commit `release/5.18.72` = `88d8442`, parent `b350192` (5.18.71, production since 10:59 UTC):** 10 files — the
page, the new test, the two re-anchored tests, `manifest.json` and the five distributor pins that exist on the release
line (`test_dist_isolation` and the three `test_partner_*` pins live only on the branch, with the undeployed portal work).
Hunks identical to the branch commit (`git diff 88d8442 008e74d -- <the ten>` is empty); `git diff --stat b350192 88d8442`
is exactly those ten. Pushed.

**`scripts/deploy-5.18.72.sh`** (pinned `88d8442` over `b350192`), the 5.18.71 script's shape: A0 refuses a pin whose
parent is not the live 5.18.71 or whose delta carries a migration or any partner-portal/CSRF file; R1 byte-for-byte; R5
Release A→5.18.71 intact; **R6** adds the four 5.18.72 markers — the `received` tile, the per-book Cash-with-staff line,
the *Advances & collections* sub-line, and **South Sudan's `collected` tile still in the file** — beside every earlier
marker; **R7** still runs 5.18.71's read-only cash-in-hand tool on the live book; **RB** checks the old wording is back on
every book and that the 5.18.71 hero and tool survived the rollback. No repair command; the rollback is printed alone at
the end of the log.

**Rehearsal `scripts/harness/deploy-5.18.72/rehearse.sh`:** against a 5.18.71 base with the pilot ON and a UGX book
holding a receipt and a Staff Advance (cash in hand 850,000). It proves the deploy PASSES and installs exactly the ten
files (one new, no migration, no portal file); the pin's page carries the Uganda tile, the per-book line and South Sudan's
tile while the base's carries only the old wording; a branch-tip pin and a placeholder pin are refused before any read;
V3/R2 read the live switch; every table is byte-identical across the deploy (R7 wrote nothing); R7 reads UGX 850,000.00 /
USD 0.00 and the base per project; **teeth**: the page reverted on the server → R1 names it and R6 names the missing tile,
line and sub-line; 5.18.71's tool removed → **R5** (it is not in this delta), R6 and R7 each name it; a Release-A file
changed → R5; a live channel or a portal file planted → R4; the rollback returns 5.18.71 exactly (old wording on every
book, the 5.18.71 hero and tool, 5.18.66–5.18.70 code intact, no data changed); an R1-blinded copy of the script is caught;
the clone is left as found.
- **Run 1 (the committed script, `c9377b4`):** **121/0, 17 runs of the script**, no FAIL line; R7 read `UGX CASH IN HAND
  850,000.00 · USD CASH IN HAND 0.00`; the clone left as found.
- **Run 2 (the committed script, unchanged — same sha256 `7075b8e9…`):** **121/0, 17 runs**, no FAIL line; the clone left as
  found. The rehearsed deploy itself reads **24 ok / 0 failed / 0 notes**, as 5.18.71's rehearsal did (its production run
  then read 23: the sandbox and the server differ by one check, as before).

**Handover.** One command, as root on the server; it asks for `DEPLOY`; send back the **log file**:

  `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.72 && mkdir -p /root/dnb-5.18.72 && bash scripts/deploy-5.18.72.sh 2>&1 | tee /root/dnb-5.18.72/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`

The rollback is printed by the script, alone, at the end of its log — never handed over beside the deploy (root docs/44
§16.9).

- **RESULT — DEPLOYED to production 2026-10-04, 11:56 UTC: PASSED, 23 ok / 0 failed / 0 notes.** The run began at
  11:56:06 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves 88d8442"*; the 10 files were
  stamped at 11:56:34 UTC. Recorded from the terminal the operator pasted (the script prints no secret); the log file stays
  on the server as `/root/dnb-5.18.72/deploy-20261004T115606Z.log`. The server reads 23 where the sandbox read 24, exactly
  as 5.18.71 did.
  - **A.** Checkout `331a5a0`; branch tip `008e74d` (not installed); release commit `88d8442` cut on `b350192`; 10 files
    (9 changed, 1 added, 0 removed), **0 migrations**; **A0** clean. Live `b350192` / 5.18.71. PHP **8.1.34** accepted the
    1 changed server file and the 8 test files. Pilot `on`; photo tables `present:3:1`, 3 files.
  - **Backup** `/root/dnb-5.18.72/backup-20261004T115606Z`: `plugin.sqlite3` 27 MB, one consistent copy, integrity ok,
    **242 tables** (241 at the 10:59 backup — one store table more, created lazily by a page opened since: the store makes
    a JSON-list table on its first load); the data directory 133 MB; the installed 5.18.71 11 MB; the vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V5** 302 / 401; **V3** the pilot
    unchanged (`on` → `on`); **V4** no fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 10 files as `88d8442` has them, manifest 5.18.72; R2 `pilot=on`; R3 084 still installed, `3:1` rows,
    untouched; R4 the pilot as before, no portal/CSRF file; R5 all 196 files from Release A through 5.18.71 intact; **R6
    the 5.18.72 wording is in place and South Sudan's `collected` / `Needs handover` branch is still in the file**, beside
    every earlier marker; **R7** (5.18.71's read-only tool) **UGX CASH IN HAND 723,072.00 · USD 10,871.37; Fiber &
    Starlink 723,072.00, DishNet 4G 0.00, BlueCARD 0.00** — the same figures as at 10:59, no cash movement since; the
    photo tables and files read exactly as before.
- **Not yet done:** the operator's look at the technician's Staff Cashbooks page on 5.18.72 (the two tiles; the figures as
  before); the 5.18.70 VOID's records log (still not
  received); Money Locations seen in a browser; `bash scripts/deploy-5.18.72.sh --after-only`.

## 04 Oct — Customer sign-in walkthrough on Uganda — FINDINGS, no change made

**Asked by the operator** after a link out of the portal answered 404 (the browser console line they pasted:
`…/_plugins/dishnet-data-report/public.php?clientId=…&kit=…&token=…` → *404 Not Found*): *"now think like end user and do
test for customer login does our app is user friendly to customer or not"*. The link's token is a ten-minute hand-off minted
by `app_data_report_token` at 12:00:27 UTC; it is not copied here and must never be. What the 404 means is read from the
code: `DishNet.openDataReport()` sends the customer to **another plugin, `dishnet-data-report`**, which is **not installed
on the Uganda host** — the portal's Starlink-fleet screens were written for the South Sudan plugin set.

**Method.** A sandbox copy of the plugin (v5.18.72) served under `/plugins/dishnet-hybrid-sudan/` the way uCRM serves it,
the **uganda** profile, UGX, one Starlink customer with one kit in a sibling kit register (`.dishnet-starlink-finance-data/
sl_kits.json`, as production evidently has one — the operator's link carried a kit number) and one unpaid invoice,
WhatsApp in dry-run. Driven over real HTTP with a cookie jar as a customer would: the sign-in page, six spellings of the
number, the mistakes, the code, the consent step, **fourteen portal screens**, the hand-off token, logout, the back button.
Script and output in the session scratchpad; nothing touched the real plugin or any server.

**What works well (keep):**
- **The number is accepted however people type it** — `0772 XXX XXX`, `0772XXXXXX`, `+256 772 XXX XXX`, `256772XXXXXX`,
  `772XXXXXX`, `+256772XXXXXX` all reach the same customer and the same WhatsApp destination; the hint reads *"Include
  country code. Uganda: +256"*.
- **The code message** is short and clear (*"Your code: ****** — Valid for 15 minutes. If you did not request this,
  ignore."*); the screen shows a live countdown from the server clock, auto-submits at six digits, offers *Resend* and the
  e-mail route; *"Wrong code."* on a wrong code; five wrong attempts need a new code; a malformed code is refused.
- **Privacy by design:** an unknown number or e-mail gets the same *"Code sent"* answer (nobody can probe which numbers are
  customers), and the help copy covers the case (*"No code after a couple of minutes? …"*). Ten codes per number per hour,
  then *"Too many requests. Try again in 1 hour."*
- **The session** is an HttpOnly cookie (no token in the page or URL); the portal before consent bounces to the consent
  step; logout revokes the session on the server — the back button then shows *"Session ended. Please sign in again"* and
  returns to sign-in in 3 s.
- **The Support screen** leads with WhatsApp, then the Uganda call and e-mail from the profile; the Terms/Privacy step is
  one tap; the portal is installable to the home screen; ~100 KB per screen, five tabs, one consistent design.

**Findings, ranked (as a Uganda customer sees them):**
1. **Usage links lead to a 404 — three places.** Home card *"Usage details →"* / *"See details →"* and the site page's
   *"Usage Details"* tile all call `openDataReport()`, which mints the hand-off token and navigates, in the same tab, to
   `dishnet-data-report/public.php` — absent here. Reproduced: the token endpoint answers 200 with a 600 s token, the link
   answers **404**. The token (customer name, phone, account list) lands in the URL of a 404 page — in browser history and
   the web server's log. **Fix:** draw those links only when `SiblingPlugin::installed('dishnet-data-report')`; otherwise
   send the customer to the in-app *Usage* view (`view=usage`, which exists) and have `app_data_report_token` refuse when
   the plugin is absent.
2. **The WiFi, Connected-devices and Hotspot features on the site page assume the same absent plugin.** *"Change WiFi"*
   (site version, `wifi_site`) shows a full form — *"Changes are sent via Starlink cloud… Update WiFi"* — whose submit
   fetches `dr_wifi_change_password` from the absent plugin; *"Connected devices"* shows *"Router offline — The router must
   be online…"* plus a developer message (*"Network error: Unexpected token '<'"*, the 404 page parsed as JSON); the
   *"Hotspot"* tile's picker says *"Checking…"* and fails the same way. The top-level *Wi-Fi Settings* (Support → *Change
   Wi-Fi password*) is honest: *"No routers found… Contact support to set up remote WiFi management."* **Fix:** one gate on
   the plugin's presence; where absent, the honest text everywhere, or hide the tiles.
3. **A Uganda customer is not told how to pay.** The invoice screen reads *"Payment reference — Reference: INV-… Amount:
   UGX 350,000 — I've paid this invoice (notifies accounts via WhatsApp)"*: no bank account, no mobile-money number, no
   *Pay Now* (DPO is not enabled for Uganda; `profiles/uganda.json` has `payment_instructions: null`). The home card says
   *"Pay to keep service active"* and the path ends there. **Fix:** the operator supplies the Uganda payment instructions
   (bank, MTN/Airtel merchant codes) for the profile — a configuration decision, then one profile edit — and/or enables DPO
   for Uganda.
4. **Usage copy promises a sync that may never come.** *"No usage data — Usage will appear once the billing cycle syncs"*
   (site page) and *"Usage tracking is not yet available for your service. This will update automatically once data
   syncs"* (Usage screen). On Uganda the only source is the plugin's own hourly collector (`cron/starlink_usage.php`),
   which needs a Starlink session on the server; *Dish status — Refresh* (`app_site_refresh`) needs the same. Whether that
   session exists on the Uganda host is **not known here**.
5. **Service status overstates:** *"All services operational · 24h uptime 100%"* is fixed text in the template, not a
   measurement. **Fix:** drop the uptime figures (keep *Report an outage* and the speed test) or measure them.
6. **Small copy and polish:** the Account screen footer says *"DishNet Africa · v4.12.20"* while the sign-in page says
   v5.18.72 (`portal.php:1293`, hard-coded); *"Require biometric at app open — Loading…"* never resolves in a browser
   (native-only row; hide it outside the app); the sign-in page blocks pinch-zoom (`maximum-scale=1,user-scalable=no`)
   while the portal allows it — remove it for customers with poor eyesight; a customer who taps *Resend* repeatedly can
   lock the number for an hour with no countdown shown.

**Verdict.** Sign-in: friendly and robust. Portal: the money and support paths are clear, but on Uganda **five tiles and
links end in a 404 or a technical error** because they belong to the South Sudan plugin set, and **the one thing a customer
most needs — how to pay — is missing**.

**One production fact only the operator can read (read-only):** `ls -la /home/unms/data/ucrm/ucrm/data/plugins/` —
which sibling plugins and `.<plugin>-data` directories exist. The site page rendered a kit, so a kit register was found;
the data-report plugin's page was not. That listing decides whether finding 1–2 is "gate on presence" alone or also
"stale data directory".

**Proposed 5.18.73 (not built):** (a) gate every data-report feature on the plugin's presence — honest *not available*
text, usage links to the in-app Usage view, the token endpoint refusing when absent; (b) Uganda payment instructions in
the profile once the operator supplies them; (c) the six small fixes above. South Sudan unchanged (the plugin is present
there, so every gate is true).

**Addendum — the production listing (operator, 12:1x UTC, read-only `ls -la …/plugins/`):** `dishnet-hybrid-sudan`,
`dishnet-data-report` (modified 3 Oct 08:25) and `dishnet-starlink-finance` (3 Oct 09:02) are all **on disk**; the only
`.<plugin>-data` directory is ours (`.dishnet-hybrid-sudan-data`), beside the vault. Two consequences:
- **The 404 is not "plugin missing".** uCRM also runs that plugin's `main.php` on its tick — the container log has carried
  its `main.php:105` flock TypeError since 25 Sep (recorded above) — so uCRM knows the plugin. The 404 is therefore either
  uCRM not serving its *public page* (disabled, or its `public.php` not in the installed copy), or that plugin's own answer
  to the client view the portal links to (`?clientId=…&kit=…&token=…`), which the Uganda copy may not have. Which one
  needs three read-only reads on the server, handed over: the plugin directory's listing (`public.php`, `manifest.json`,
  `ucrm.json` present?), its manifest version, and the HTTP status of `GET …/_plugins/dishnet-data-report/public.php` and
  `…?action=dr_wifi_get_status&router_id=x` from the server (uCRM's 404 for both = not served; JSON or a non-404 for the
  second = served, and the client-view route is the problem).
- **"Gate on presence" would change nothing here.** `SiblingPlugin::installed()` is `is_dir()`, true on Uganda. The 5.18.73
  gate must be *"the page answers"*: a server-side probe of the plugin's public page with a cached verdict (an hour), or an
  explicit setting — never a directory test.
- **A fragility recorded, not new:** with no `.dishnet-starlink-finance-data` or `.dishnet-data-report-data` directory, the
  kit register and the usage files live under `<plugin>/data`, the directory uCRM deletes when that plugin is upgraded
  (`cron/dr_snapshot.php` exists for exactly this). The site page's kit comes from there today.

**Addendum 2 — the three reads (operator, read-only, ~12:2x UTC):** `dishnet-data-report` is a **complete, uCRM-installed
plugin**: `ucrm.json` (1 Oct 06:31), `manifest.json` **v2.8.80** (2 Jul), `main.php`, `cron.php`, `client.php`,
`dr_wifi_change.php`, `public.php` **hand-edited on the server on 3 Oct 08:25** (412 KB, with a root-owned
`public.php.bak.<epoch>` beside it) and a root-owned `official_api/` directory (3 Oct 07:57); its `data/` was written at
11:45 today, so its cron runs. **The plain public page answers 200 and `?action=dr_wifi_get_status&router_id=x` answers
200**: uCRM serves it. **So the 404 is that plugin's own answer to the portal's client-view link**
(`?clientId=…&kit=…&token=…`). Two candidate causes, both on the other plugin's side:
- **the token does not verify there.** Our hand-off is signed with `JwtAuth::legacySecret()` =
  `sha256(webhook_secret | crm_app_key (or crm_auth_token) | 'DishNet-Hybrid-JWT-v2-2026')` — the Hybrid plugin's own two
  configuration values. The data-report plugin can only accept it if it derives the same secret from the **same two
  values**; on an install made on 1 Oct from the South Sudan build, they very likely differ. A plugin that answers 404 to
  an unverifiable token is answering as designed.
- **the client or kit is not in that plugin's own data** (its caches are rebuilt by its cron from the Starlink account it
  is configured for), and the client view says *not found*.
  Handed over, read-only: the body of the 404 with and without a token; `grep -n clientId public.php | head`;
  `grep -n -i "secret\|verify" public.php | head`; `ls -la data/`. **Nothing on our side can be concluded until then**;
  the 5.18.73 proposal is amended: the hand-off to the other plugin should be a **tenant setting** (on where that plugin is
  configured to accept it, as in South Sudan; off on Uganda until it is), with the in-app Usage view as the default — not a
  presence probe, which this case defeats, and not a "page answers" probe, which this case also defeats (200 on the page,
  404 on the view).

**Addendum 3 — ROOT CAUSE (operator's four reads, ~12:3x UTC, read-only).** Without a token the client view answers **200**
(*"DishNet — Client Fleet"*); with a bad token it answers **404 "Report Not Found"** — the plugin's own page. Its code
(`drVerifyHybridJwt`, its lines 839–908) verifies our hand-off by rebuilding the secret *"exactly like Hybrid's
lib/JwtAuth.php::fromConfig()"*: `sha256(webhook_secret | crm_app_key|crm_auth_token | constant)` read from the Hybrid
plugin's own stored configuration, and **returns null — hence 404 — when either input is empty** (its line 902: *"never
an attacker-predictable constant secret"*). **On the Uganda install both inputs are empty**, and have been since the plugin
was installed: the 5.18.37 record says *"On an install whose `webhook_secret` is empty — Uganda's was recorded empty"*, and
the 5.18.38 record says *"the customer token signed with `sha256(webhook_secret | crm_auth_token | constant)` where both
inputs are empty — a key anyone can read in the source"* — which is why 5.18.38 moved customer sessions to the dedicated,
generated, vaulted `CustomerJwtKeys`… **and kept the legacy derivation for the data-report hand-off only** (*"signed the
way that plugin has always been given"*). Neither value is a settings-form field in `manifest.json`; both are written only
by the old admin form (`post_admin.php`), and `crm_auth_token` doubles as the manual uCRM API credential
(`CrmApiClient`). South Sudan's row holds a 32-character `webhook_secret` and a 64-character `crm_auth_token` (the other
plugin's own comment), so the hand-off verifies there. **Conclusion: on Uganda the portal mints a hand-off token under a
constant, empty-input secret; the other plugin refuses it; every Usage link ends in that plugin's 404. Not a bug in either
plugin's routing — a configuration the Uganda install never had.** Confirmation, read-only, in the container:
`php tools/config_trace.php webhook_secret crm_auth_token` (prints set/empty and lengths, never values).

- **Also found:** the Hybrid mints that token without checking its inputs. A hand-off signed under an all-empty secret is
  forgeable by anyone who reads the source; the other plugin's refusal is the only thing that makes this harmless. The
  mint (`app_data_report_token`) should **refuse** (409 *hand-off not configured*) when either input is empty.
- **Three ways to make the Usage links work on Uganda, for the operator to choose:**
  **A** — set `webhook_secret` and `crm_auth_token` on the Uganda Hybrid install so both plugins derive the same secret.
  Not recommended as a quick fix: `crm_auth_token` would also become the plugin's uCRM API credential (it must then be a
  valid uCRM app key), and `webhook_secret` is read by other boundaries (the partner-portal work on the branch).
  **B** — a dedicated hand-off key both plugins read (generated and vaulted on our side, configured on theirs). Clean, but
  it needs a change in the data-report plugin too — not in this repository, and its `public.php` on this server was
  hand-edited on 3 Oct by someone who may own that side.
  **C** — a tenant setting: the hand-off is drawn only where it is configured (South Sudan); on Uganda the Usage links go to
  the portal's own Usage screen, which exists and reads the plugin's own hourly collector. Can ship now, touches only this
  plugin, and South Sudan is unchanged. **Recommended first; B later if the data-report usage view is wanted on Uganda.**

**Addendum 4 — CONFIRMED (operator, `config_trace.php webhook_secret crm_auth_token` in the container, ~12:4x UTC):** both
keys are **NOT SET in every layer** — the plugin's `data/config.json`, uCRM's `config.json`, `kyc_config.json`, the vault,
`PluginConfig::load()` and `effectiveConfig()` all read *absent*. The root cause stands as stated. The tool's first line,
*"[ConfigVault] restored after re-install: dpo_enabled, dpo_environment, dpo_company_token, dpo_payment_method_uuid,
dpo_ptl, dpo_test_clients, dpo_test_link_key, dpo_currencies, pdf_link_secret"*, is about that one process, not a change:
`PluginConfig::load()` is the boot path and runs `ConfigVault::apply()`, which **supplies in memory** every vault key the
store copy lacks and names them; on this install those nine live only in the vault (the DPO and PDF-link code fill them
from there on each request by design). `refresh()` then compares the snapshot with the vault file and **returns without
writing when the content is identical** — it was — and had it written, `SecureFile::write` adopts the directory's owner
at 0640, which is why the tool could print *nginx:nginx · 0640* after the load. **Nothing on the server changed.** Lesson
recorded: a tool that goes through the boot path is not read-only by construction; `PluginConfig::read()` is the path
that *"changes nothing on disk"*, and the next diagnostic handed over must use it. **Decision pending: A, B or C above.**

## 04 Oct — 5.18.73: the customer portal keeps Uganda customers in the app — the Data Report hand-off is a tenant setting; the hand-off token is refused under empty signing inputs; six walkthrough fixes — DEPLOYED 16:24 UTC (PASSED 24/0/0), ROLLED BACK 16:59 UTC on the operator's decision (PASSED 18/0/1); production is 5.18.72 again

**Decided by the operator** (*"c build it"*) after Addendum 4 above confirmed the root cause: on the Uganda install the
portal minted a ten-minute hand-off token signed with `sha256(webhook_secret | crm_auth_token | constant)`, **both
empty in every configuration layer**, and sent the customer to `dishnet-data-report`'s client view, which rebuilds that
secret, refuses an empty input and answered its own 404 to every Usage link. Option **C**: the hand-off becomes a
per-country setting — South Sudan on (its install holds both values, so the hand-off verifies there; nothing changes),
Uganda off (the customer stays in this app, on its own Usage screen) — and the mint refuses to sign anything under empty
inputs, whatever the tenant says. Options A (set the two values on Uganda) and B (a dedicated hand-off key read by both
plugins) stay open for later; **Uganda payment instructions (finding 3) are NOT in this release** — the operator has not
supplied the bank or mobile-money details the profile needs.

**What changed** (`44c7dcf` on the branch, release `9fd9f88` on `88d8442`):
- **`profiles/south-sudan.json`** gains `integrations.data_report_handoff: true` — the behaviour before this release,
  written down. **`profiles/uganda.json`** gains `integrations.data_report_handoff: false` with a `_readme` recording why
  (4 Oct 2026: this install has neither signing value) and how to switch it on.
- **`TenantProfile::dataReportHandoff()`**: the explicit configuration key **`portal_data_report_handoff`** (`yes`/`no`,
  declared in `manifest.json` and accepted by `tools/set_config.php`, which refuses any other value) wins; else the
  profile value; else *on* (a profile without the key behaves as before).
- **The portal** (`portal_data.php` sets `$portalDataReportHandoff`): the home card's fleet link *"Usage details →"* and
  the site page's *"Usage Details"* tile are drawn **only where the tenant has the hand-off** (the site tiles collapse to
  one column; the card above already says what the site used); the single-service *"See details →"* opens the in-app
  **Usage** view where it is off. **`DishNet.openDataReport()` never navigates without a token**: a refused mint tells
  the customer *"Usage details are not available here yet. Your usage is shown in this app; ask support if you need
  more."* — the old `.catch(function () { location.href = url; })`, which navigated tokenless, is gone.
- **The API** (`app_data_report_token`): **409** *Usage details are not available here* where the tenant has no hand-off;
  **409, audited `data_report_handoff_unconfigured`** (naming the missing input, never a value) where `webhook_secret` or
  `crm_auth_token`/`crm_app_key` is empty — so a token can no longer be minted under a constant, all-empty secret even
  where the setting says yes. `JwtAuth::legacySecret()` is still called exactly once, the way the other plugin has always
  been given it.
- **The six small fixes from the walkthrough:** the Account footer reads the installed version from `manifest.json` (was
  a hard-coded *v4.12.20*); the *Require biometric at app open — Loading…* row is hidden unless the Android bridge is
  present; the sign-in page's viewport allows pinch-zoom (`maximum-scale=1,user-scalable=no` removed, as the portal
  already allowed); Service status says **"No outage reported"** / *"Offline? Report it below"* instead of the invented
  *"All services operational · 24h uptime 100% / 99.8% / 99.5%"* strips (a paused service still reads paused); Connected
  devices says *"This feature is not available right now. Contact support if it continues."* instead of the JSON parser's
  *"Unexpected token '<'"*. Not changed: the WiFi / Connected-devices / Hotspot tiles themselves — the data-report
  plugin's action routes answer 200 on Uganda (Addendum 2), so whether they work for a Uganda router is that plugin's
  question, not a hand-off one; the Usage copy's *"once data syncs"* wording (finding 4; the collector's state on Uganda
  is still unknown here); the resend lockout countdown.
- `manifest.json` 5.18.73; the nine pins. **No migration, no new table, no uCRM write, no message, no configuration value
  changed, nothing written to any record.**
- **South Sudan keeps every Usage link and the hand-off — measured, not assumed.** The same South Sudan customer (both
  signing inputs set, as that install has them) was signed in on a sandbox built from the live 5.18.72 release commit and
  on one built from the 5.18.73 release commit, and eleven pages plus the token endpoint were saved from each: the
  hand-off token answers **200, issued, 600 s, three segments** on both; the home card carries the same two
  `openDataReport()` handlers and the site page the same two and its *Usage Details* tile on both. The pages differ only
  where this release means them to — the shared `openDataReport()` body and the biometric-row toggle in the page script
  (identical on every page), whitespace around the two gated links (the PHP gate tags eat a line break; the links are
  byte-identical), the service-status strips, the devices message, the Account footer and biometric wrapper, and the
  sign-in page's viewport and version line. Nothing else moved. (Probe and saved pages in the session scratchpad.)

**Proofs.**
- `tests/test_portal_handoff.php` (new): **34/0**. **A** Uganda default — no `openDataReport(` click handler on the home
  card, the one-column site grid, the token endpoint 409, the footer `v5.18.73`, the biometric row hidden, no *24h
  uptime*, the sign-in page (fetched with an empty cookie jar) carries no zoom block. **B** Uganda with
  `portal_data_report_handoff=yes` and **empty inputs** — the links draw, the mint still answers 409 and writes exactly one
  `data_report_handoff_unconfigured` audit row. **C** Uganda, yes, both inputs set — links drawn, token 200 in the legacy
  shape. **D** South Sudan — 200 with the inputs, 409 without. **E** `set_config.php --value maybe` exits 1; `YES` is
  saved as `yes`. **F** four weakened copies, each caught: the home gate forced true, the API tenant check disabled, the
  API empty-input check disabled, the Uganda profile set to true.
- `test_customer_session` now runs with the hand-off on and both inputs set (it tests the token's shape): **80/0**.
- Neighbours, unchanged: `test_tenant_profile` 108 · `test_set_config_tool` 40 · `test_config_one_truth` 16 ·
  `test_preauth_allowlist` 105 · `test_customer_pwa` 31 · `test_portal_tenant` 112 · `test_customer_login_security` 68 ·
  `test_consent_identity` 34 · `test_otp_log_privacy` 16 · `test_canonical_host` 49 · `test_notify_tenant_text` 30 ·
  `test_invoice_template_links` 21 · `test_ucrm_link` 78 · `test_sales_support_tenant` 37 · `test_staff_cashbook_wording`
  23 · `test_notify_customer_fixes` 32 — all 0 failed.
- **The full plugin suite on the final 5.18.73 tree:** **`tests/run.sh` 270 files, 12,298 passed, 0 failed, 3 skipped** (the three clock-bound checks of `test_notify_evo_retry`, skipped by design at Sun 13:12 UTC, as at 5.18.71 and 5.18.72; the fourth skip line is that test's own note). Was 269 / 12,264 at 5.18.72: one file and 34 assertions more, the new hand-off test (34; the session test is unchanged at 80). Run on the committed `44c7dcf` plugin tree — the same files the release commit `9fd9f88` carries — while the rehearsal ran.

**Release commit `release/5.18.73` = `9fd9f88`, parent `88d8442` (5.18.72, production since 11:56 UTC):** 16 files — the
two profiles, `TenantProfile`, the portal page, its data loader and the sign-in page, the customer API, the configuration
tool, `manifest.json`, the new test, the session test and the five distributor pins that exist on the release line.
Hunks identical to the branch commit (`git diff 9fd9f88 44c7dcf -- <the sixteen>` is empty). Pushed.

**`scripts/deploy-5.18.73.sh`** (pinned `9fd9f88` over `88d8442`), the 5.18.72 script's shape: A0 refuses a pin whose
parent is not the live 5.18.72 or whose delta carries a migration or any partner-portal/CSRF file; R1 byte-for-byte; R5
Release A→5.18.72 intact; **V6** reads the sign-in page on the public address and fails if it still carries
`user-scalable=no` or `maximum-scale=` (skipped on a rollback — 5.18.72's page blocks zoom by that release's design);
**R6** adds the 5.18.73 markers — both profile values, the `TenantProfile` method, the portal-data flag, the portal's two
gates (counted: exactly 2), **no tokenless fallback**, the mint's refusal, zoom allowed, no *24h uptime*, no hard-coded
version — beside every earlier release's marker; **R7** still runs 5.18.71's read-only cash-in-hand tool on the live
book; **RB** checks the hand-off is unconditional again and that 5.18.72's wording survived. No repair command; the
rollback is printed alone at the end of the log.

**Rehearsal `scripts/harness/deploy-5.18.73/rehearse.sh`:** against a 5.18.72 base with the pilot ON and a UGX book
holding a receipt and a Staff Advance (cash in hand 850,000). The stand-in sign-in page now carries the **installed**
plugin's own viewport line — a control proves 5.18.72's blocks zoom and the pin's allows it — so V6 reads real code. It
proves the deploy PASSES and installs exactly the sixteen files (one new, no migration, no portal file); the pin carries
the setting, the two gates, the mint's refusal and both profile values while the base has none; a branch-tip pin and a
placeholder pin are refused before any read; V3/R2 read the live switch; every table is byte-identical across the deploy
(R7 wrote nothing); R7 reads UGX 850,000.00 / USD 0.00 and the base per project; **teeth**: `TenantProfile` reverted on
the server → R1 names it and R6 names the missing setting; the sign-in page reverted → **V6 fails, counting the zoom
block (×1 · ×1)**, and R1 names the file; 5.18.71's tool removed → R5, R6 and R7 each name it; a Release-A file changed →
R5; a live channel or a portal file planted → R4; the rollback returns 5.18.72 exactly (the hand-off unconditional, the
5.18.72 wording, the 5.18.71 hero and tool, 5.18.66–5.18.70 code intact, no data changed); an R1-blinded copy of the
script is caught; the clone is left as found.
- **Run 1 (the committed script, `8b5ce61`, sha256 `e85b4066…`):** **134/0, 18 runs of the script**, no FAIL line; the
  stand-in's viewport control held both ways; R7 read `UGX CASH IN HAND 850,000.00 · USD CASH IN HAND 0.00`; the clone
  left as found.
- **Run 2 (the committed script, unchanged — same sha256):** **134/0, 18 runs**, no FAIL line; the clone left as found.
  The rehearsed deploy itself reads **25 ok / 0 failed / 0 notes** (5.18.72's rehearsal read 24: V6 is the one check
  more; its production run read 23 where its sandbox read 24, so expect 24 on the server this time).

**Handover.** One command, as root on the server; it asks for `DEPLOY`; send back the **log file**:

  `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.73 && mkdir -p /root/dnb-5.18.73 && bash scripts/deploy-5.18.73.sh 2>&1 | tee /root/dnb-5.18.73/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`

The rollback is printed by the script, alone, at the end of its log — never handed over beside the deploy (root docs/44
§16.9).

**After the deploy, what a Uganda customer sees:** the home card says *"View all sites"* with no *"Usage details"* link
(or *"See details →"* opens the Usage screen, with one site); a site page has no *"Usage Details"* tile; the Account
footer reads *v5.18.73*; Service status reads *"No outage reported"*; the sign-in page zooms. Nothing leaves the app for
the Data Report plugin. **To switch the hand-off on for Uganda later** (option A or B): set both signing values, then
`php tools/set_config.php --key portal_data_report_handoff --value yes` in the container — the mint refuses until both
values are set, however the key reads.

**Still open from the walkthrough:** Uganda payment instructions for the profile (the operator's bank and MTN/Airtel
details); the Usage collector's state on the Uganda host; the WiFi / devices / Hotspot tiles against a Uganda router; the
resend lockout countdown; and, for whoever owns the data-report plugin, that its client view answered **200 with no token
at all** (*"DishNet — Client Fleet"*) — what it shows anonymously should be checked there.

- **RESULT — DEPLOYED to production 2026-10-04, 16:24 UTC: PASSED, 24 ok / 0 failed / 0 notes** — the 24 the rehearsal
  predicted (5.18.72 read 23; V6 is the one check more). The run began at 16:24:00 UTC; `DEPLOY` was typed and
  `deploy-hybrid.sh` answered *"✓ container now serves 9fd9f88"*; the 16 files were stamped at 16:24:27 UTC. Recorded from
  the terminal the operator pasted (the script prints no secret); the log file stays on the server as
  `/root/dnb-5.18.73/deploy-20261004T162400Z.log`.
  - **A.** Checkout `8891c38`; branch tip `44c7dcf` (not installed); release commit `9fd9f88` cut on `88d8442`; 16 files
    (15 changed, 1 added, 0 removed), **0 migrations**; **A0** clean. Live `88d8442` / 5.18.72. PHP **8.1.34** accepted the
    6 changed server files and the 7 test files. Pilot `on`; photo tables `present:6:2`, 6 files (3:1 and 3 at 11:56 —
    technicians have taken photos since; the deploy touched neither).
  - **Backup** `/root/dnb-5.18.73/backup-20261004T162400Z`: `plugin.sqlite3` 27 MB, one consistent copy, integrity ok,
    242 tables; the data directory 135 MB; the installed 5.18.72 11 MB; the vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V6 the sign-in page allows
    pinch-zoom on the public address**; **V5** 302 / 401; **V3** the pilot unchanged (`on` → `on`); **V4** no fatal or
    parse error in the 60 s after the copy.
  - **R.** R1 all 16 files as `9fd9f88` has them, manifest 5.18.73; R2 `pilot=on`; R3 084 still installed, `6:2` rows,
    untouched; R4 the pilot as before, no portal/CSRF file; R5 all 197 files from Release A through 5.18.72 intact; **R6
    the hand-off is a tenant setting, the portal gates on it, the token is refused under empty inputs, zoom allowed, no
    invented uptime, no hard-coded version**, beside every earlier marker; **R7** (5.18.71's read-only tool) **UGX CASH IN
    HAND 723,072.00 · USD 10,871.37; Fiber & Starlink 723,072.00, DishNet 4G 0.00, BlueCARD 0.00** — the same figures as
    at 10:59 and 11:56, no cash movement since; the photo tables and files read exactly as before.
- **Not yet done:** a Uganda customer's look at the portal on 5.18.73 (home card without the *Usage details* link, a site
  page without the *Usage Details* tile, the Account footer at v5.18.73, *No outage reported*, the sign-in page zooming);
  `bash scripts/deploy-5.18.73.sh --after-only`; the Uganda payment details for the profile; the 5.18.70 VOID's records
  log and the Money Locations look (still open from the earlier entries).
- **ROLLED BACK 2026-10-04, 16:59 UTC, on the operator's decision — PASSED, 18 ok / 0 failed / 1 note.** The operator
  wrote *"how to cancel last update usage link i want to keep"*: they want the portal's *Usage details* link kept. Given
  three routes (the setting, making the hand-off verify, the rollback) they ran **two**: first the setting
  `portal_data_report_handoff = yes` (stored at about 16:5x UTC through `tools/set_config.php`; on 5.18.72 no code reads
  it, so it is inert until 5.18.73 or later is installed again, when it will draw the links and let the mint decide),
  then `deploy-5.18.73.sh --rollback`, typed `ROLLBACK`. The documented deploy put `88d8442` back; the 16 files were
  stamped at 16:59:13 UTC; V1–V4 green (sign-in 200 with no redirect, the portal 302, no South Sudan contact, the pilot
  `on` → `on`, no fatal in 60 s); **RB** all seven: manifest 5.18.72, the hand-off unconditional again, and the 5.18.72,
  5.18.71, 5.18.70/69, 5.18.68 and 5.18.66/67 code intact. Backup `/root/dnb-5.18.73/backup-20261004T165847Z` holds the
  5.18.73 install. Recorded from the pasted terminal; the log file is on the server.
  - **State of production now:** 5.18.72. The *Usage details* link, the *See details* hand-off and the *Usage Details*
    tile are drawn again for Uganda customers **and still open the Data Report plugin's 404 page**, because the two
    signing values are still empty — the rollback changed code only. The hand-off token is again minted under the
    all-empty secret. The six small fixes (installed version in the footer, biometric row, sign-in zoom, no invented
    uptime, devices message, no tokenless navigation) are off production again.
  - **What would make the link work on 5.18.72 — and why it cannot be done from the screens today.** The hand-off verifies
    once `webhook_secret` and `crm_auth_token` are both set in the Hybrid's stored configuration. `crm_auth_token` **is**
    settable: Settings → *UCRM Connection* → *Admin Auth Token* (a real uCRM API token; the field says Quotes need it
    anyway). **`webhook_secret` is not:** the Settings tab shows it **read-only with a Copy button** (*"Setup Webhook never
    generates it as a side effect"*), the field has no form name so the admin form never posts it, and `set_config.php`
    does not accept the key. **Corrected from the first draft of this bullet**, which said both were Settings fields.
    So making the link work needs either a small release that gives the operator a way to set the secret, or the Data
    Report side accepting a different key (option B). Cautions, from the code: the uCRM webhook refuses a request only
    when it carries a **different** `X-Crm-Key`, so an empty uCRM webhook secret stays harmless; `crm_auth_token`
    overrides the automatic uCRM API credential **only when `crm_base_url` is also set**, so the base URL stays empty.
  - **Also visible in the pasted settings:** Uganda's payment instructions already exist in configuration —
    `ai_fact_payment` (Airtel Money merchant, Ecobank account, how to reference a payment) and `pay_airtel_merchant` —
    written for the AI assistant and the quotations. Finding 3 of the walkthrough (*a Uganda customer is not told how to
    pay*) can therefore be closed from configuration the operator has already approved: a later release can show the
    same instructions on the portal's invoice screen. Not built; noted for the operator's decision.
  - **Branch and release state unchanged:** `release/5.18.73` = `9fd9f88` stays valid against the live `88d8442`;
    `deploy-5.18.73.sh` installs it again whenever wanted (its summary printed the command). The six fixes are only
    available by installing 5.18.73 or a later release.

## 04 Oct — 5.18.74: the Usage links stay; the hand-off token is refused until the signing values exist; `webhook_secret` can be generated, never shown; "How to pay" on the invoice screen — BUILT, rehearsed, NOT deployed

**Decided by the operator** (*"Yes."* to the proposal that followed the 5.18.73 rollback). 5.18.73 had switched the Data
Report hand-off off in the Uganda profile; the operator wants the *Usage details* link kept. 5.18.74 keeps everything else
5.18.73 built and sets that value **on**, so the link, the *See details* hand-off and the site page's *Usage Details* tile are
drawn as on 5.18.72. What changes for the customer, until the two signing values exist: a tap no longer leads to the Data
Report plugin's 404 page with a token in the address — the mint answers **409, audited `data_report_handoff_unconfigured`**,
and the page says *"Usage details are not available here yet."* The release also gives the operator the missing way to set
the one value no screen could set, and shows the configured payment instructions on an unpaid invoice.

**What changed** (`b4b4c80` on the branch, release `db18ad9` on `88d8442`, the 5.18.72 release commit live again since
16:59 UTC):
- **`profiles/uganda.json`:** `integrations.data_report_handoff: true`, with a `_readme` recording the decision, the gap
  (this install holds neither signing value) and how to close it. `portal_data_report_handoff=no` still hides the links;
  the manifest and the tool say *blank = the country profile (yes in both since 5.18.74)*.
- **`tools/set_config.php` — `webhook_secret` as a `secret` key.** `--generate` stores `bin2hex(random_bytes(16))`
  through `PluginConfig::saveOverrides`, which writes the override file **and** mirrors the store row the web requests
  read (`public.php` builds `$config` from `$store->load('kyc_config.json')`). `--value` is **refused**: a secret is never
  typed into a shell. A second `--generate` is refused while a value is set (the store row counts too); rotation is
  `--clear`, then `--generate`, explicitly. The listing and the success line read *"set (32 characters) — not shown"* and
  never the value. `webhook_secret` was never in `SECRET_KEYS` (the refused list), so no writer changed. With the Admin
  Auth Token set in Settings → UCRM Connection, `app_data_report_token` then mints a token that verifies under
  `sha256(webhook_secret | crm_auth_token | constant)` — what the Data Report plugin rebuilds. The Settings tab's
  read-only *Webhook Secret* field keeps its Copy button for the one case that needs the value: a uCRM webhook configured
  to send a key (unset there, uCRM sends none and nothing is refused).
- **The invoice screen — "How to pay".** An unpaid invoice shows the operator's own `ai_fact_payment` text verbatim
  (line breaks kept) above the Payment reference block, where that setting exists and is not `omit`
  (`portal_data.php` `$portalPayText`). Uganda has it (Airtel Money merchant, Ecobank account, how to reference a
  payment — written for the assistant and the quotations); South Sudan has it unset and keeps its Bank transfer block
  from the profile. Walkthrough finding 3 (*a Uganda customer is not told how to pay*) is closed from configuration the
  operator had already approved; the text ends *"send … the transfer confirmation here"*, which on this screen is the
  *I've paid this invoice* button below it — the operator can reword the setting if wanted.
- **Kept from 5.18.73:** the tenant setting and `TenantProfile::dataReportHandoff()`; the mint's two refusals;
  `DishNet.openDataReport()` never navigating without a token; the Account footer reading the installed version; the
  biometric row hidden outside the native app; the sign-in page allowing pinch-zoom; Service status without invented
  uptime; the Connected-devices message.
- `manifest.json` 5.18.74; the nine pins. **No migration, no new table, no uCRM write, no message; the deploy changes no
  configuration value — the secret is the operator's own command afterwards.** South Sudan: the profile says yes and both
  inputs are set there, so the mint answers exactly as before; `ai_fact_payment` is unset there.

**Proofs.**
- `tests/test_portal_handoff.php` (rewritten): **62/0**. **A** Uganda default — the link and the tile drawn, the mint 409
  with **one** `data_report_handoff_unconfigured` row, the page's own message, the six 5.18.73 fixes. **A2** the key says
  `no` — 5.18.73's behaviour (no link, one-column grid, 409 with no "unconfigured" audit). **B/C** the key with and without
  the inputs. **C2 the whole chain on a bare Uganda install with only the Admin Auth Token:** `--value` refused and nothing
  saved; the key alone asks for `--generate`; `--generate` on a non-secret refused; `--generate` → exit 0, a 32-hex secret
  in the file **and** the store row, the output carrying **no 32-hex string** and reading *not shown*; the bare listing
  shows *set (32 characters) — not shown*; a second `--generate` refused with the value unchanged; **the token endpoint
  answers 200 and the token verifies under the legacy derivation**; `--clear` removes both copies and the mint refuses
  again. **D** South Sudan unchanged. **G** "How to pay": set / `omit` / unset / the South Sudan control (its Bank transfer
  block). **F six weakened copies, each caught:** the home gate forced true under `no`, the mint ignoring the tenant, the
  mint ignoring empty inputs, the Uganda profile back to `false`, the invoice screen ignoring `ai_fact_payment`, **the tool
  printing the generated secret**.
- Neighbours, unchanged: `test_set_config_tool` 40 · `test_config_one_truth` 16 · `test_tenant_profile` 108 ·
  `test_customer_session` 80 · `test_portal_tenant` 112 · `test_customer_pwa` 31 · `test_preauth_allowlist` 105 ·
  `test_invoice_template_links` 21 · `test_notify_customer_fixes` 32 · `test_customer_login_security` 68 ·
  `test_consent_identity` 34 · `test_canonical_host` 49 — all 0 failed.
- **The full plugin suite on the final 5.18.74 tree:** **`tests/run.sh` 270 files, 12,329 passed, 0 failed, 0 skipped** — the three clock-bound checks of `test_notify_evo_retry` ran this time (the run fell inside the follow-up sending window) and passed. Was 270 / 12,298 at 5.18.73: 28 assertions more, the hand-off test grown from 34 to 62, plus those three. Run on the committed `b4b4c80` plugin tree — the same files the release commit `db18ad9` carries — while the rehearsals ran.

**Release commit `release/5.18.74` = `db18ad9`, parent `88d8442`:** the same sixteen files as 5.18.73's release — the two
profiles, `TenantProfile`, the portal page, its data loader and the sign-in page, the customer API, the configuration
tool, `manifest.json`, the hand-off test, the session test and the five distributor pins on the release line. Hunks
identical to the branch commit (`git diff db18ad9 b4b4c80 -- <the sixteen>` is empty). Pushed.

**`scripts/deploy-5.18.74.sh`** (pinned `db18ad9` over `88d8442`), 5.18.73's shape: A0 refuses a pin whose parent is not
the live 5.18.72 or whose delta carries a migration or any partner-portal/CSRF file — **and it refuses a live 5.18.73**,
so 5.18.73 must not be deployed again first; R1 byte-for-byte; R5 Release A→5.18.72 intact; V6 the sign-in page allows
zoom (skipped on a rollback); **R6** expects the Uganda profile **on** and the 5.18.73 `false` absent, the secret generator
and its never-shown message in the tool, `$portalPayText` and *How to pay* in the portal, beside every earlier marker; R7
runs 5.18.71's read-only cash-in-hand tool; RB checks 5.18.72 is back. The deploy sets no secret: that is the operator's
own command afterwards. No repair command; the rollback is printed alone at the end of the log.

**Rehearsal `scripts/harness/deploy-5.18.74/rehearse.sh`:** 5.18.73's, against the same 5.18.72 base; the pin carries
the generator and *How to pay* and the base neither; the stand-in viewport control; every teeth check (TenantProfile
reverted → R1 and R6; the sign-in page reverted → V6 and R1; the tool removed → R5/R6/R7; a Release-A file → R5; a live
channel or portal file → R4); the rollback to 5.18.72; the R1-blinded copy; the clone left as found.
- **Run 1 (the committed script, `919deb8`, sha256 `3aa6ba54…`):** **134/0, 18 runs of the script**, no FAIL line; the
  stand-in viewport control held both ways; R7 passed on the seeded book; the clone left as found.
- **Run 2 (the committed script, unchanged — same sha256):** **134/0, 18 runs**, no FAIL line; the clone left as found.
  The rehearsed deploy itself reads **25 ok / 0 failed / 0 notes**, as 5.18.73's did; its production run read 24 where
  the sandbox read 25, so expect **24 ok** on the server.

**Handover.** Three steps, each its own command, in this order. **Step 1**, as root on the server; it asks for `DEPLOY`;
send back the **log file**:

  `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.74 && mkdir -p /root/dnb-5.18.74 && bash scripts/deploy-5.18.74.sh 2>&1 | tee /root/dnb-5.18.74/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`

The rollback is printed by the script, alone, at the end of its log — never handed over beside the deploy (root docs/44
§16.9). **Step 2**, after the deploy passed — the secret, generated and stored, never shown:

  `docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key webhook_secret --generate`

**Step 3**, in the plugin's Settings tab → *UCRM Connection* → *Admin Auth Token*: paste a uCRM API token (uCRM → My
Profile → API tokens → Create; the field says Quotes need it too), leave *CRM Base URL* blank, save. Then open a Usage
link as a customer: it should open the Data Report. If it still answers 404, the other plugin reads the two values from a
different file than the store row and the override file this plugin writes; one read of its code
(`grep -n "kyc_config\|config.json\|sqlite" …/dishnet-data-report/public.php | head`) will say which, and nothing on
our side changes until then.

**After the deploy, what a Uganda customer sees** (before steps 2–3): *Usage details* is there; a tap says the details are
not available yet, no 404; an unpaid invoice shows *How to pay*; the Account footer reads v5.18.74; Service status reads
*No outage reported*; the sign-in page zooms. After steps 2–3: the Usage links open the Data Report.

**Still open from the walkthrough:** the Usage collector's state on the Uganda host; the WiFi / devices / Hotspot tiles
against a Uganda router; the resend lockout countdown; and, for whoever owns the data-report plugin, that its client view
answered **200 with no token at all**.
