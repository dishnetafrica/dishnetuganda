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

**Deploy.** `scripts/deploy-5.18.43.sh`, pinned to the plugin commit __COMMIT43__; stage V re-checks everything
5.18.42 checked. The label itself is seen only by a signed-in customer with a discounted invoice, so the test
suite is its proof. **Not deployed by this session.**
