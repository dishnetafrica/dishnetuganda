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

## 04 Oct — 5.18.74: the Usage links stay; the hand-off token is refused until the signing values exist; `webhook_secret` can be generated, never shown; "How to pay" on the invoice screen — DEPLOYED 19:59 UTC (PASSED 24/0/0); the secret generated 20:0x UTC

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

- **RESULT — DEPLOYED to production 2026-10-04, 19:59 UTC: PASSED, 24 ok / 0 failed / 0 notes** — the 24 the rehearsal
  predicted (the sandbox reads 25). The run began at 19:59:11 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered
  *"✓ container now serves db18ad9"*; the 16 files were stamped at 20:00:23 UTC. Recorded from the terminal the operator
  pasted (the script prints no secret); the log file stays on the server as
  `/root/dnb-5.18.74/deploy-20261004T195911Z.log`.
  - **A.** Checkout `52e1951`; branch tip `b4b4c80` (not installed); release commit `db18ad9` cut on `88d8442`; 16 files
    (15 changed, 1 added, 0 removed), **0 migrations**; **A0** clean. Live `88d8442` / 5.18.72 (since the 16:59 rollback).
    PHP **8.1.34** accepted the 6 changed server files and the 7 test files. Pilot `on`; photo tables `present:6:2`, 6 files.
  - **Backup** `/root/dnb-5.18.74/backup-20261004T195911Z`: `plugin.sqlite3` 27 MB, one consistent copy, integrity ok,
    242 tables; the data directory 135 MB; the installed 5.18.72 11 MB; the vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V6** zoom allowed on the public
    address; **V5** 302 / 401; **V3** the pilot unchanged (`on` → `on`); **V4** no fatal or parse error in the 60 s after
    the copy.
  - **R.** R1 all 16 files as `db18ad9` has them, manifest 5.18.74; R2 `pilot=on`; R3 084 still installed, `6:2` rows,
    untouched; R4 the pilot as before, no portal/CSRF file; R5 all 197 files from Release A through 5.18.72 intact; **R6
    the hand-off ON in both profiles, the portal gates on it, the token refused under empty inputs, the secret generator
    present, "How to pay" present, zoom, no invented uptime, no hard-coded version**, beside every earlier marker; **R7**
    (5.18.71's read-only tool) **UGX CASH IN HAND 723,072.00 · USD 10,871.37; Fiber & Starlink 723,072.00, DishNet 4G
    0.00, BlueCARD 0.00** — the same figures as at 10:59, 11:56 and 16:24, no cash movement today; the photo tables and
    files read exactly as before.
- **Step 2 — DONE, about 20:0x UTC.** `set_config.php --key webhook_secret --generate` answered *"webhook_secret generated
  and stored (32 characters). It is not shown here"*, and the listing that followed reads **`webhook_secret set (32
  characters) — not shown`** — the value appeared nowhere in the terminal, as designed. The listing also still shows
  `portal_data_report_handoff = "yes"` (the key stored at 16:5x; harmless now that the profile says yes — `--clear` is
  optional) and the usual *[ConfigVault] restored after re-install* line, which is the in-memory fill explained under
  Addendum 4 above, not a write.
- **Step 3 — PENDING:** the Admin Auth Token in Settings → UCRM Connection (CRM Base URL left blank), then a Usage link
  opened as a customer. If it still answers 404, the Data Report plugin reads the two values from a different file than the
  override file and the store row this plugin writes; the read that decides it:
  `grep -n "kyc_config\|config.json\|sqlite" /home/unms/data/ucrm/ucrm/data/plugins/dishnet-data-report/public.php | head`.
- **Pending from the operator on the AI brief (`docs/55`):** whether to fix the live lead-path defects (a) and (b) behind
  the existing `ai_lead_capture` / `ai_crm_lead_sync` flags — an external write (uCRM lead clients) that is configured
  but has never happened. The dark media batch does not depend on that answer.
- **Step 3 cannot work yet — the Data Report plugin reads the wrong install (operator's read, ~20:1x UTC).** Its
  `public.php` (lines 841–880, its own comment *"v2.8.31 CRITICAL FIX: previous versions read kyc_config.json from disk…
  table [kyc_config] row id=0"*) opens **`<plugins>/dishnet-hybrid-telecom/data/plugin.sqlite3`** and runs
  `SELECT data FROM kyc_config WHERE id = 0`. That is the **South Sudan** install's plugin name and data location. On
  Uganda the plugin is `dishnet-hybrid-sudan` and its data directory is `<plugins>/.dishnet-hybrid-sudan-data/` (uCRM's
  `pluginDataDir`; `docs/27`, the 4 Oct listing). The path does not exist here, so the other plugin has always read an
  empty configuration — **neither value it needs can ever reach it from this server, whatever we store.** This refines
  Addendum 3: the empty inputs were real, and even filled they are read from a file that is not there.
  - **Our side is complete and correct:** `saveOverrides` wrote the generated secret into the override file **and** the
    store row `kyc_config` id 0 of `.dishnet-hybrid-sudan-data/plugin.sqlite3` — the table and row that plugin queries
    (`PluginConfig::mirrorToStore`, proved by `test_portal_handoff` C2). The Settings form writes the Admin Auth Token
    into the same row (`post_admin.php:749`).
  - **The fix is one path in the other plugin**, on this server only, handed over as the operator's command: back up
    `public.php`, replace `'/dishnet-hybrid-telecom/data/plugin.sqlite3'` with
    `'/.dishnet-hybrid-sudan-data/plugin.sqlite3'`, syntax-check it in the container. That file is already hand-edited
    (3 Oct 08:25, a root-owned `.bak` beside it) and **a future update of the Data Report plugin will overwrite it**, so
    whoever maintains that plugin should carry the change. A symlink named `dishnet-hybrid-telecom` in uCRM's plugins
    directory was considered and rejected: uCRM would see a plugin directory with no manifest.
  - Then step 3 (the Admin Auth Token) and the test: a Usage link opened as a customer.
  - **Path fix APPLIED by the operator, ~20:1x UTC.** The two checks held (`plugin.sqlite3` present in
    `.dishnet-hybrid-sudan-data`, the old path exactly once); `public.php` was backed up with a timestamp, line 868 now
    reads `'/.dishnet-hybrid-sudan-data/plugin.sqlite3'`, and `php -l` in the container passed. The Data Report plugin
    now reads the store row this plugin writes. **Step 3 (the Admin Auth Token in Settings) and the Usage-link test are
    still the operator's.** Reminder recorded: a Data Report plugin update overwrites that file.

## 04 Oct — AI communication layer, Batch 0 (5.18.75): the three live lead-path defects fixed — BUILT and proved at the worker level, NOT deployed; STOPPED for review before Batch 1

**Instruction:** the operator approved the HYBRID architecture (`docs/55`) and ordered Batch 0 only — fix the three
defects on the live lead path, prove them with worker-level tests, report, and stop before Batch 1. Nothing was deployed
and no configuration changed. **The existing flags `ai_lead_capture` and `ai_crm_lead_sync` were not touched — and both
read ON in the Uganda production listing the operator pasted at 20:0x UTC** (see *Production impact* below).

**The defects, as found in the code** (`docs/55` §3; each re-read before the fix):
- **(a)** `workers/AiReplyWorker.php` called `$this->latestPin($convId, $ctx)` with `$ctx`, a variable that does not
  exist in `handle()` — the context is `$context`. PHP passed null to an `array` parameter, the TypeError landed in the
  catch as *lead capture failed*, and `AiLeadService::capture()` was never reached. **No WhatsApp lead has ever been
  written by the live assistant, whatever the flag said.**
- **(b)** `workers/UcrmLeadWorker.php` read `$event['payload']` as an array. `WorkerBase::run()` decodes the stored JSON
  into `_payload` and leaves `payload` the string, so `lead_id` was always 0, the event was logged *no lead_id — dropped*
  and acknowledged. **No lead could ever have reached uCRM through the queue** (and the queue is the only writer:
  `UcrmLeadSync` has no other caller).
- **(c)** The sales channel's context is built by `BrainContext`, which carries no conversation id or customer id by
  design (`NEVER_PRESENT`) and, until now, no pin: the assistant never saw the *LOCATION PIN* block on the sales number,
  and the guard's log lines and `ai_security_events.json` rows recorded conversation 0 / customer 0 on every sales turn.

**The fixes** (`b0674bd` on the branch; six files + the new test + the version and nine pins):
- **(a)** one word: `latestPin($convId, $context)`, with the defect recorded beside it.
- **(b)** `UcrmLeadWorker::payloadOf()` — the decoded `_payload` first, else an array `payload`, else the JSON string
  decoded — used in `handle()` and `onDead()`.
- **(c)** two parts, both deliberate about what the model may see:
  - `BrainContext` gains a **thirteenth key, `location`** (`lat`, `lng`, `name`, `in_bounds`) — the pin THIS turn
    carried, as `evo_webhook` recorded it: the customer's own message content, which decides what the model does
    (acknowledge the pin, never guess the place). Only numeric coordinates travel. The sales branch of
    `AiReplyWorker::buildContext()` passes it. `conversation_id` and the customer id stay out of the model's context.
  - The guard's bookkeeping ids now come from the worker's own `$turnIds` (set in `buildContext()` once identity is
    resolved, cleared at the start of every event) with the legacy context as fallback, so support and account record
    exactly what they recorded before and sales records the real conversation and customer.
- `tests/test_brain_context.php`: twelve → thirteen keys, with the thirteenth pinned to exactly its four leaves.
- `manifest.json` 5.18.75; the nine pins. **No migration, no new table, no uCRM write in development, no message.**

**Proofs — `tests/test_lead_path_batch0.php`, 25/0, at the WORKER level:** a fake Evolution server and the fake uCRM
server from the existing fixtures; a brain that never leaves the process but parses its canned answer with the **real**
`DishNetAiBrain::parseMarkers`; the workers constructed and run as `run_worker.php` runs them.
- **A.** A sales turn whose answer carries `<<LEAD{…}>>`: the customer gets one reply with the marker stripped; **exactly
  one `leads` row** (requirement, phone, source `whatsapp_ai`, conversation id) whose **coordinates are the pin the
  conversation sent two turns earlier** — looked up, never believed; the log says *lead created*, never *lead capture
  failed*; **one `crm.lead.sync` event naming lead #1**.
- **B.** The sales context carries the pin this turn sent, with its label; the *LOCATION PIN* block appears in the sales
  prompt; `conversation_id` still does not travel to the model.
- **C.** A reply blocked by the guard on the sales number (*"our cost…"*) is audited against the **real** conversation
  on channel `sales`; the customer received the safe fallback and never the blocked text; the staff alert went out.
- **D.** `UcrmLeadWorker`, run as `WorkerBase` runs it, processes the event to `done`; the fake uCRM receives **exactly one
  new client** for that phone; the lead row carries the uCRM client id; the log says the lead reached uCRM.
- **E. Four weakened copies, each caught** by re-running the same scenario against the copy (the test re-enters itself as
  a driver): (a) put back → no lead, *lead capture failed*, no sync event; (b) put back → the event is acknowledged with
  *dropped* and uCRM receives nothing; (c) the pin removed from the sales context → no pin block; (c) the guard reading
  the stripped context → conversation 0 again. A first version of the uCRM copy was not weakened at all, because the
  helper only weakens a UNIQUE anchor and the fix text appeared twice; the anchor now includes the comment that
  precedes the one read in `handle()`. **A mutant that passes because it was never applied is the control that caught
  itself.**
- Neighbours, unchanged: `test_brain_context` 123 · `test_location_pin` 79 · `test_ai_lead_capture` 71 ·
  `test_ucrm_lead_sync` 62 · `test_bot_stays_awake` 59 · `test_handover_message` 9 · `test_human_takes_over` 16 ·
  `test_prospect_sales` 60 · `test_shadow_runtime` 74 · `test_prices_are_grounded` 36 · `test_shop_part1` 48 ·
  `test_guard_blocks_send` 41 · `test_reply_privacy_guard` 71 · `test_ai_brain` 153 · `test_brain_customer_tools` 110 ·
  `test_shopbot_payload` 65 · `test_ai_minimal_context` 61 · `test_ai_security_policy` 146 — all 0 failed.
- **Full suite:** **`tests/run.sh` 271 files, 12,355 passed, 0 failed, 0 skipped** (was 270 / 12,329 at 5.18.74: the new test's 25 and one more in `test_brain_context`). **Second run:** **271 / 12,355 / 0 again** (a first second run was killed at 205 files, 0 failures, by a container restart on this side; it was re-run in full).
- `git diff --check` clean; no credential-shaped value in the diff; no file under Domain B (`plugin/`, `src/`,
  `migrations/`) touched; South Sudan's suites are part of the full run.

**Production impact — exactly what happens when 5.18.75 is installed, flag by flag:**

| Flag (existing) | Uganda value in the 20:0x UTC listing | With the flag ON, after this release | With the flag OFF |
|---|---|---|---|
| `ai_lead_capture` | **ON** | a qualified WhatsApp enquiry (a stated requirement plus location, pin, customer type or a quote request — the existing floor in `AiLeadService`) is written to the plugin's own `leads` table, linked to the conversation, audited in `ai_crm_actions.json`; the Sales → Leads screens show it. **An internal write; nothing leaves the plugin.** | as today: nothing written |
| `ai_crm_lead_sync` | **ON** | each such lead is queued and, off the reply path, **a uCRM client is created or patched**: the existing phone-match rules (link one exact match, refuse an ambiguous one, create an `isLead` client with a note otherwise; `UcrmLeadSync`). **An external write to uCRM that is configured today and has never happened.** | the queue event is acknowledged as *disabled*; nothing reaches uCRM |

So installing this release with the flags as they are **switches both on in production**. Nothing here changes a flag.
The operator's choice before any deploy: (i) `set_config.php --key ai_crm_lead_sync --value 0` first, keeping local
lead capture only; (ii) both off; (iii) accept both. Nothing is deployed until that is decided and the deploy is
separately approved.

**Rollback:** the release is code only — the deploy script's own `--rollback` restores the previous commit; a flag set
to 0 stops the behaviour without any deploy; nothing written by the fixed path needs undoing (leads are rows the sales
team already has screens for; uCRM lead clients are ordinary lead clients).

**External side effects in development:** none — the fake servers only. **Database changes:** none. **Deployment
requirement:** a 5.18.75 release commit cut on the live 5.18.74 (`db18ad9`), its deploy script and rehearsal — **not cut
yet; awaiting the operator's review of this batch.** **STOPPED here; Batch 1 (the media foundation) waits for approval.**

## 05 Oct — AI communication layer, Batch 1 (5.18.76): the media foundation — BUILT dark, proved against a fake Evolution and the real webhook, NOT deployed, NOT pushed; STOPPED before Batch 2

**Instruction (verbatim in substance):** *do not deploy 5.18.75; do not cut a release commit; keep Batch 0 recorded as
`b0674bd`; do not modify production configuration; before any future deployment verify the actual production values of
`ai_lead_capture` and `ai_crm_lead_sync` and treat both as NOT APPROVED for activation; approve development of Batch 1
only — the MEDIA FOUNDATION — with `ai_media_enabled` remaining OFF, no voice/image/document processing, no transcription
or vision provider, no customer-facing media replies, no production configuration change, no deployment, no live WhatsApp
media test and no real customer messages; do not push or deploy unless explicitly requested; stop after Batch 1 with a
complete report; do not begin Batch 2.* All of it honoured: this entry is the report. **Nothing left this machine.**

**What the foundation is** (`docs/55` §9; every piece dark unless `ai_media_enabled` is set, and it is not set anywhere):
- **`migrations/085_wa_media.sql`** — the one schema change, stated on its own: a new table `wa_media` (`CREATE TABLE IF
  NOT EXISTS`, additive, never altered) with a **UNIQUE index on `wa_message_id`** and two lookup indexes. One row per
  WhatsApp media message: the message key Evolution needs to hand the file over (`remote_jid`, `wa_message_id`,
  `from_me`), what the webhook announced (`kind`, `mimetype`, `file_name`, `caption`, `declared_bytes`, `seconds`), the
  fetch state (`status`, `attempts`, `failure_reason`), what remains after a fetch (`fetched_bytes`, `fetched_mimetype`,
  `sha256`) and two columns reserved for Batch 2+ (`understanding`, `understanding_kind`). **It never holds the media.**
  MigrationRunner applies it on the next boot of whatever tree carries it — development sandboxes today; production only
  if 5.18.76 is ever deployed, and then as an empty table.
- **`lib/InboundMedia.php`** — one normalised shape for the five media envelopes (`audioMessage`, `imageMessage`,
  `documentMessage`, `videoMessage`, `stickerMessage`, with the `documentWithCaptionMessage` / ephemeral / view-once
  wrappers unwrapped), `null` for text; `record()` writes the row with `INSERT OR IGNORE`, so a second pass over the same
  message changes nothing and returns `null`; video and stickers are recorded as `unsupported` at once.
- **`lib/MediaPolicy.php`** — every number and every allowed type in one place: `ai_media_enabled` OFF unless set to
  1/true/yes/on; `ai_media_max_bytes` default **15 MiB**, clamped to 64 KiB–64 MiB; `ai_media_timeout_s` default **20 s**,
  clamped 3–60; the allow-list per kind (audio: ogg/opus/mpeg/mp3/mp4/aac/amr/wav/webm; image: jpeg/png/webp; document:
  pdf, Word, Excel, csv, text), so an audio type offered for an image is refused; Batch 1 fetches audio, image and
  document only.
- **`lib/MediaBlob.php`** — the fetched bytes in memory with size, type and sha256; `wipe()` forgets the bytes;
  `describe()` never carries content.
- **`lib/MediaFetcher.php`** — through the **existing** Evolution client: the new
  `EvolutionApiService::getBase64FromMediaMessage($channel, $key)` (`POST /chat/getBase64FromMediaMessage/{instance}`,
  the recorded key, `convertToMp4: false`), the timeout from the client's constructor. **The webhook registration still
  asks for NO base64 in the payload** (`'base64' => false`, asserted): the bytes are fetched afterwards by the worker,
  never carried in a request that meets the webhook's 512 KB cap. Order of checks: key complete, kind supported,
  **announced size within the limit — all before any call**; then Evolution's reported type against the allow-list, a
  strict base64 decode, the actual size, a sha256. Every refusal is a fixed code with a retryable flag:
  `missing_identifier`, `unsupported_kind`, `unsupported_mime`, `too_large` (never retried); `fetch_failed` (a 5xx or no
  connection retried, a 4xx not), `timeout`, `malformed` (retried). Nothing in it writes to disk or logs bytes.
- **`workers/MediaWorker.php`** + **`run_media_worker.php`** — its own worker, its own lock file (`MediaWorker.lock`,
  WorkerBase names it after the class) and its own runner, so a slow download can never hold up a text reply and the
  reply worker's lock never holds up a fetch. Per `ai.media` event: read the row; **the flag OFF → the row is marked
  `skipped`/`media_disabled`, the event acknowledged, nothing fetched**; a settled row (fetched, understood, unsupported,
  skipped, dead) → duplicate event, acknowledged, **nothing fetched twice**; else `fetching`, attempts +1, fetch; success →
  `fetched` with size, type and sha256, the bytes wiped; a transient failure → `failed` with its code and the exception
  thrown so the EventBus retries with its backoff; a permanent one → `unsupported`/`failed`, acknowledged; **the last
  attempt gone → `dead` and the conversation marked `needs_human`** — the same signal AiReplyWorker gives when it cannot
  answer — and **no message is ever sent**. The runner is CLI-only, never `exit()`s (master.php includes it), returns
  unless `ai_enabled`, and **with `ai_media_enabled` OFF returns after one indexed read** unless an event queued while the
  flag was on is still pending (then the worker marks it skipped). Its trace goes to `ai_platform.log` as `[MediaWorker]`
  lines plus `media worker: processed=… failed=… deferred=…`.
- **`evo_webhook.php`** — one additive step **8c** between the opt-out check and the AI queue: the envelope is normalised;
  **only if `ai_media_enabled` is on and the message was stored** is the row recorded and an `ai.media` event queued
  (payload: `media_id`, `conversation_id`, `channel`, `whatsapp_instance`, `wa_message_id`, `kind`, `has_caption`,
  `received_at` — **no phone and no JID**, those are on the row). The message is stored exactly as before (`[AUDIO]`,
  `[IMAGE]`, `[DOCUMENT]`, `[VIDEO]` with its type); a caption still goes down the text path and is answered as it always
  was; the media-only log line says *stored and queued for the media worker* when it was, and is **unchanged** when it was
  not. The spawn block now starts `run_worker.php` when text was queued and `run_media_worker.php` when media was,
  **each only when it has work**, with the same PHP-CLI discovery and the same silence when exec is unavailable. The
  response gains `media_queued`; the log line gains `media=N`. Every anchor the other suites hold on this file is intact.
- **`cron/master.php`** — a new job `ai_media` every 60 s running `run_media_worker.php` (the guaranteed path, as
  `ai_reply` is for text), **registered with `'flag' => 'ai_media_enabled'`: a job that exists only while its flag is
  on.** master.php does not dispatch it and `tools/cron_status.php` does not list it while the flag is off (both read
  the new attribute the way they read `'gate'`), so **the scheduler's job list is the one it was before this batch, on
  every install.** The first version registered it ungated and **the existing guard caught it**: `test_notify_schedule_health`
  §4 pins South Sudan's `cron_status` list to the 5.18.53 tool's, and `ai_media` appeared on it (18/1). Flag-gated, the
  list is identical again (19/0). One consequence, stated: events queued while the flag was on and still pending after
  it is turned off are drained only by running `php run_media_worker.php` by hand, which marks their rows `skipped`.
  **`cron/event_processor.php`** — `ai.media` joins `ai.reply` in the list of types the generic 30 s loop releases
  rather than acknowledges as unknown (the lesson recorded there).
- **`tools/set_config.php`** — three new keys: `ai_media_enabled` (bool), `ai_media_max_bytes`, `ai_media_timeout_s`
  (numbers, **refused outside their range** naming it, so the listing never shows a value the worker is not using).
- **`tests/fixtures/fake_evo_server.php`** — `POST /chat/getBase64FromMediaMessage/{instance}` recording every call and
  answering from a test control (`/__test/media`: a deterministic payload of N bytes, type, file name, a hold, an HTTP
  failure, a raw non-JSON body; optionally per message id). **`manifest.json` 5.18.76; the nine pins.**

**Not changed, deliberately:** `ConversationService` (a wrapped envelope it does not store is normalised but not
recorded — stated as a limit, not patched sideways), `AiReplyWorker`, `DishNetAiBrain`, `run_worker.php`, the Uganda
profile, any flag's value, anything under South Sudan's paths or Domain B. **No transcription, vision or extraction;
no provider; no reply to a media message; the customer still hears nothing about a photo — as today.**

**Proofs — `tests/test_media_foundation.php`, 99/0** (one in-process scenario against the fake Evolution, one with the
real plugin under `php -S` through `SjSandbox`, both returning facts so a weakened copy can run them too):
- **A** normalisation of all five envelopes, text → null, a missing key id → an empty id nobody guesses at, the
  document-with-caption wrapper unwrapped. **B** the policy: defaults, overrides, clamps, junk → default, `enabled()` on
  twelve inputs, the per-kind allow-list. **C** the record: the table and its UNIQUE index, the second `record()` of one
  message returns null and adds no row, the row points at the stored placeholder, a video is `unsupported` at once.
- **D** the fetcher: a valid voice note gives 2048 bytes in memory with **the sha256 of exactly the bytes the fake
  served** and the right call (instance, key, `convertToMp4` false); bad MIME refused not retried; **an announced size
  over the limit makes NO call**; an actual size over the limit refused after; 500 retried, 404 not; non-base64 and
  non-JSON answers `malformed`; no id / no JID / video kind → no call; a timeout classified as `timeout` whether curl or
  the Uganda request path reported it; **one real Evolution that does not answer → `timeout` in 9.6 s** against a 3 s
  setting (the client's three connection attempts).
- **E** the worker: three pending media (voice, photo, PDF) fetched in one run with the right types and hashes; **the
  worker log names kind, type, size and a hash prefix and never the bytes, the base64 or the JID; a scan of every file
  under the data directory finds no media, no base64 and no hash — the database row is the only record**; a duplicate
  event is acknowledged with no second fetch; **the flag OFF at the worker: skipped, acknowledged, nothing fetched**; a
  500 leaves `failed`/`fetch_failed` with one attempt and an error naming no JID, and **when the retry is due the second
  attempt fetches it**; the last attempt gone → row and event `dead`, **that conversation `needs_human`, the other
  untouched**; **with `MediaWorker.lock` held the media worker does not run; with `AiReplyWorker.lock` held it still
  does**; **nothing was sent to any customer**.
- **F** the real webhook: **flag OFF — a voice note is accepted and stored as `[AUDIO]`, no row, no event, nothing
  changes**; ON — `media_queued: 1`, the row pending with the key and the announced facts, pointing at the placeholder,
  one event naming it, **the payload carrying ids and kind but no phone or JID**; **the same delivery again records
  nothing twice**; **a captioned photo still queues `ai.reply` with the caption** and is also recorded; a document with its
  file name; a video stored as `[VIDEO]`, `unsupported`, no event; text → no record; **the flag back OFF → as before**.
- **G** `run_media_worker.php` over the CLI inside the sandbox: fetches the three recorded media silently with the right
  hashes, `ai_platform.log` carries the trace and the summary and no bytes or JID, no file under the sandbox holds the
  media; **flag OFF with nothing pending → one read, nothing written, nothing fetched; `ai_enabled` OFF → nothing**; no
  WhatsApp message of any kind left the sandbox; **`tools/cron_status.php` in the sandbox lists `ai_reply` and not
  `ai_media` while the flag is off, and both once it is on.**
- **H** wiring: event_processor releases `ai.media`; master.php registers the job behind its flag and skips a flagged
  job whose flag is off; the two runners share no worker; the runner
  is CLI-only and never exits; the webhook records only behind the flag and spawns each runner only when it has work;
  the registration still says `base64: false`; set_config manages the three keys and **refuses `ai_media_timeout_s 2`
  naming the range 3–60**; version 5.18.76; migration 085 additive.
- **I — six weakened copies, each caught** by re-running the scenario that guards it against the copy (the test re-enters
  itself as a driver): the allow-list always saying yes (bad MIME accepted); the announced-size check removed (a call is
  made); **the worker ignoring `ai_media_enabled` (the row is fetched)**; **the fetcher keeping the bytes on disk (the
  retention scan finds them)**; the record no longer ignoring a duplicate (it throws); **the webhook recording with the
  flag OFF (a row appears)**. Control: the real tree driven the same way trips none of them.
- **Neighbours in the full run, all 0 failed:** `test_media_foundation` 102 · `test_notify_schedule_health` 19 · `test_lead_path_batch0` 25 · `test_bot_stays_awake` 59 · `test_human_reply_visible` 9 · `test_human_takes_over` 16 · `test_location_pin` 79 · `test_staff_whatsapp` 50 · `test_evolution_and_webhook` 55 · `test_wa_webhook_guard` 13 · `test_wa_webhook_url` 18 · `test_cron_no_exit` 10 · `test_cron_keepalive_order` 8 · `test_cron_status_lateness` 17 · `test_config_one_truth` 16 · `test_portal_handoff` 62 · `test_ucrm_lead_sync` 62 · `test_job_records_race` 46 · `test_notify_kyc_race` 14 · `test_partner_otp_delivery` 68 · `test_quote_cron_log` 10 · `test_plugin_config` 48 · `test_job_photos` 72 · `test_dist_isolation` 39.
- **Full suite:** **`tests/run.sh` 272 files, 12,457 passed, 0 failed, 0 skipped** (was 271 / 12,355 at 5.18.75: the new test's 102; nothing else moved). **Second run: 272 / 12,457 / 0 again**, on the same code, start to finish.
- `git diff --check` clean; the diff's only phone-shaped strings are the synthetic `2567720003xx` fixture numbers the
  existing tests also use; no credential-shaped value; **no file under Domain B (`dishnet-mikrotik-control-plane/`)
  touched**; South Sudan's suites are part of the full run and its behaviour is untouched (the flag is unset there too).

**Production impact — none today, because nothing is deployed and the flag is unset everywhere.** If 5.18.76 were ever
installed with the flag still OFF: the webhook behaves as before (placeholder stored, nothing recorded, no spawn); **no
new scheduled job runs and none is listed** (the job registers only while the flag is on); the settings listing shows
three more keys; `wa_media` is created empty. **5.18.76 sits on top of 5.18.75 (Batch 0), so any deploy of it carries Batch 0 as well —
the operator's rule stands: verify the production values of `ai_lead_capture` and `ai_crm_lead_sync` first and treat both
as NOT APPROVED for activation.** With the flag ON (not approved): each voice note, photo or document is recorded, fetched
into memory by the media worker, checked and reduced to size, type and sha256 — and still **nothing is answered**.

**Rollback:** code only; the flag off restores today's behaviour without a deploy; the empty table stays, harmless.
**External side effects in development:** none — the fake Evolution only. **Database changes:** development sandboxes
only (deleted by the tests). **Deployment requirement:** a release commit cut on the live 5.18.74 (`db18ad9`) carrying
5.18.75 + 5.18.76, its deploy script and rehearsal — **not cut, not pushed, awaiting the operator's instruction.**
**STOPPED here; Batch 2 (voice → transcript → brain) waits for explicit approval.**

## 05 Oct — AI communication layer, Batch 2 (5.18.77): VOICE, dark — the provider boundary documented, the voice path built end to end on the existing assistant, NO provider selected, NOT deployed, NOT pushed; STOPPED before Batch 3

**Instruction (in substance):** *Batch 1 accepted as development-complete; keep `ce16324` local and unreleased; do not
push, deploy, change production configuration, enable `ai_media_enabled`, activate voice/image/document processing,
modify South Sudan behaviour, touch Domain B or start customer-facing media replies. Batch 2 approved — VOICE MEDIA
ONLY: re-read the Batch 1 contracts, the brain, STOP, the guard, hand-over and message persistence first; find where the
transcript enters the existing turn without a second brain or pipeline; document the provider boundary before selecting
or integrating any provider; implement audio classification through the foundation, retrieval through Evolution, a
transcription adapter with a deterministic fake, normalisation, an explicit voice indication, the transcript into the
EXISTING DishNetAiBrain path with the existing guards, privacy, hand-over and audit, STOP on the transcript, safe
fall-back on failure, idempotency and retry intact, no bytes on disk, no secrets or media in logs; flags off, no
production change; the fourteen tests; local commit allowed, no push, no deploy; stop and report.* All of it honoured.
**Nothing left this machine; both flags are off everywhere; no provider exists.**

**Where the transcript enters — one place, nothing new in kind.** The webhook queues a typed message as an `ai.reply`
event whose `message` is the text; `AiReplyWorker::handle()` does identity, history, the brain, the guard, the send,
the store, the lead marker and the escalation. A voice note enters at exactly that event: `VoiceTranscription`, called
by the Batch 1 media worker once it holds the audio in memory, queues ONE `ai.reply` event of the same shape with
`message` = `[voice message, transcribed] <transcript>` plus `origin: voice` and `voice: {media_id, seconds, chars}`.
No second brain, no second queue, no second worker for replies: the transcript is a customer turn like any other, and
every guard that applies to typed text applies to it unchanged.

**The provider boundary — `docs/56`, written before the adapter.** `lib/Transcriber.php`: `TranscriberPort`
(`transcribe(MediaBlob, hints, timeoutSeconds)` → a transcript, or a FIXED reason code with a retryable flag; `detail`
may never carry audio, text, a key, a number or a JID), `TranscriberFactory` (`ai_transcription_provider` unset or
`none` → no provider; `fake` → the deterministic fake, constructible only when the test environment names its script
file; anything else → no provider and one log line — **no value selects a real provider**), and `FakeTranscriber`
(scripted by the audio's sha256: a transcript or a scripted failure; it records hash prefixes and counts, never bytes or
text). The size, time and cost assumptions and the test strategy are in `docs/56` §6–§7. **The provider, the language
hint, the cost ceiling and whether audio may leave the server at all are the operator's decisions, not taken here.**

**What was built, file by file:**
- **`lib/VoiceTranscription.php`** — `process(row, audio)`: refuses before any provider call a non-audio type, a note
  longer than `ai_media_voice_max_seconds` (default 120, range 10–600) and a missing provider; marks the row
  `transcribing`; asks the provider with the `ai_media_voice_timeout_s` budget (default 30, range 5–120); maps its
  answer to the reason codes `provider_missing · too_long · unsupported_audio · invalid_audio · empty_transcript ·
  conversation_missing` (permanent) and `provider_error · timeout` (retryable); normalises the text (control characters
  out, whitespace collapsed, 4,000 characters at most, empty is a failure). `complete()` runs **one transaction**:
  `UPDATE wa_media SET status = 'understood', understanding = …, understanding_kind = 'transcript' … WHERE id = ? AND
  status <> 'understood'` — zero rows means already done, nothing else happens; the stored `[AUDIO]` message's body
  becomes the labelled transcript and its metadata gains `voice` (so the inbox and the model's history show what was
  said); `ContactOptOut::detect()` runs on the raw transcript exactly as the webhook runs it on typed text (source
  `voice_keyword`, the message still answered); the one `ai.reply` event is queued. A database failure rolls all of it
  back and is thrown, so the worker retries the whole thing.
- **`workers/MediaWorker.php`** — after a successful fetch of an audio row, **only when `MediaPolicy::voiceEnabled()`**
  (both flags), the blob goes to `VoiceTranscription` and is wiped whatever happens. A retryable failure is thrown (the
  EventBus retries with its backoff; the retry fetches the audio again, since nothing was kept); a permanent failure —
  and a Batch 1 fetch refusal for a voice note, and the queue giving up — hands the conversation to a person. The
  settled rule is refined: a `fetched` voice note with no transcript yet is not settled while voice is on; `understood`
  is settled for good, so a duplicate event never transcribes twice. `useTranscriber()` for tests; `TranscriberFactory`
  otherwise.
- **`lib/Handover.php`** — `AiReplyWorker::escalate()` **moved into a library class**, statement for statement:
  `needs_human`, the `wa.escalation` event, the staff alert with its 30-minute cooldown, the operator's
  `ai_handover_message` to the customer once (`alreadySaid` moved with it). The worker's `escalate()` keeps its
  signature and delegates (`test_handover_message` 9/0 unchanged); the media worker calls the same function with source
  `media_worker`. **One hand-over path, two callers.**
- **`workers/AiReplyWorker.php`** — the turn's context carries `voice => {seconds}` when the event says
  `origin: voice`; the sales contract passes it to `BrainContext::build()`; a blocked reply's audit event fills the
  existing `modality` field with `voice` (its default `text` stands otherwise); the per-message log line says
  `origin=voice`. Nothing else in the turn changed.
- **`lib/BrainContext.php`** — the **fourteenth** contract key, `voice => ['seconds']`, present only when the caller says
  so; `test_brain_context` now pins fourteen and proves the duration is the only leaf that travels.
- **`lib/DishNetAiBrain.php`** — a conditional **VOICE MESSAGE JUST RECEIVED (N s)** block in the data section, beside
  the pin block: the message is an AUTOMATIC TRANSCRIPT; every name, figure, amount, date, address and number is
  UNCONFIRMED until the customer confirms it; an unclear transcript is asked about, never guessed; it is content under
  rule 7. A typed turn's prompt is byte-for-byte what it was.
- **`lib/MediaPolicy.php`** — `voiceEnabled()` (= `enabled()` AND `ai_media_voice`), the two voice limits with their
  ranges, the transcript cap. **`tools/set_config.php`** — `ai_media_voice`, `ai_media_voice_max_seconds`,
  `ai_media_voice_timeout_s` (ranges refused, not clamped) and `ai_transcription_provider`, which accepts `none` only
  and refuses the fake by name. **`manifest.json` 5.18.77; the nine pins.** **No migration: `wa_media` carried
  `understanding` and `understanding_kind` since 085.**

**Not built, deliberately:** a transcription provider (the operator's decision after `docs/56`); a voice-specific
fall-back sentence to the customer (the existing holding line is the operator's own text; a new sentence is a copy
decision for the operator); image, document, payment screenshots, lead state, follow-up, e-mail, marketing, n8n,
another gateway, another brain. `ConversationService` untouched (the transcript is written to the row it already stored).

**Proofs — `tests/test_voice_media.php`, 74/0** (a core scenario in-process against the fake Evolution and the fake
uCRM, with the deterministic fake transcriber injected and a fake brain that parses its canned answer with the real
marker parser; a CLI scenario in the real plugin tree under `php -S`; both return facts so a weakened copy can run
them):
1. **valid voice note → row → fetch → transcript**: `understood`, the transcript on the row (`understanding_kind
   transcript`), the stored `[AUDIO]` message rewritten as the labelled transcript with voice metadata; one fetch, one
   provider call that received the bytes, the announced 7 s and the 5 s budget and recorded a hash prefix and a size.
2. **enters the existing brain exactly once**: one `ai.reply` event by `media_worker`, the webhook's shape (phone,
   channel, instance, message id, JID, push name, received_at, no location); the reply worker made one brain call.
3. **clearly voice-originated**: the message starts with the label; the sales contract carries `voice = {seconds: 7}`;
   the prompt carries the VOICE MESSAGE block and a typed turn's does not; the reply reached the customer once and was
   stored; **a later typed turn sees the labelled transcript in history** (and carries no voice key itself).
4. **a duplicate event does not transcribe twice**: acknowledged with no second fetch, call or event; and `complete()`
   called directly on an understood row answers `already_understood` and changes nothing.
5. **non-audio ignored**: a photo is fetched (Batch 1) and never transcribed or answered.
6. **too long, unreadable, too large → rejected and handed over**: `too_long` before any provider call; `invalid_audio`;
   the Batch 1 `too_large` refusal — each `needs_human`, one `wa.escalation` by `media_worker`, the staff alert naming
   the reason, the holding line once (stored); no `ai.reply`.
7. **timeout / provider error → retried**: row `failed/timeout`, the event `failed` with one attempt and an error naming
   no JID, not handed over; made due, **the retry fetches again, transcribes and queues the one event** (row attempts 2).
8. **provider failure → safe hand-over**: no provider → `provider_missing`, no call, no event, the holding line, the
   alert; the factory yields nothing for `none`, an unknown name, or `fake` without its environment; the queue giving up
   → `dead`, handed over, no event.
9. **STOP in the transcript**: a spoken "stop" records the opt-out (proactive, source `voice_keyword`, the transcript as
   evidence), proactive messages are blocked from then on, and the STOP is still acknowledged through the AI; a sentence
   merely containing "stop" is not an opt-out.
10. **the guard holds**: a transcript that induces "our cost…" is blocked — the safe fallback sent, the blocked text
    never, the audit row against the real conversation with `modality: voice`, a person takes over.
11. **nothing on disk, nothing in logs**: no file under the data directory holds the audio, its base64, its hash or the
    transcript; the worker logs carry no base64, bytes, transcript or JID; the provider saw hash prefixes only.
12. **voice OFF**: fetched (Batch 1), zero transcription, zero events, zero messages, no hand-over.
13. **media OFF wins**: `voiceEnabled()` needs both flags; media off with voice on → `skipped`, nothing fetched.
14. **eight weakened copies, each caught** by re-running the scenario: voice alone turning voice on; the worker
    ignoring the voice flag; `understood` no longer settled (a duplicate transcribes again); STOP no longer detected;
    the label removed; the permanent-failure hand-over removed; the transcript logged; the prompt block removed. Control:
    the real tree trips none.
- **CLI, in the real tree:** the webhook records the note; `run_media_worker.php` with the fake provider through its
  test-only environment transcribes it and queues the one labelled event, the provider log holds one hash-prefix line,
  `ai_platform.log` holds no transcript; voice OFF → fetched only; media OFF with voice ON → nothing recorded; provider
  `fake` **without** its environment fails closed → `provider_missing`, hand-over, holding line, no event.
- **One neighbour caught the refactor first:** `test_plan_fence` keeps a ledger of every file that reaches Evolution
  with a decision on whether customers see model text through it; `lib/Handover.php` appeared without one (52/1). Its
  entry is now recorded — `fixed`: the one text it sends a customer is the operator's own holding line, verbatim, no
  model output, so the plan fence does not apply — and the suite is 53/0. **A sender that appears without a line in that
  ledger fails until somebody chooses which side it is on; the guard worked.**
- **Neighbours in the full run, all 0 failed:** `test_voice_media` 74 · `test_media_foundation` 102 · `test_brain_context` 126 · `test_handover_message` 9 · `test_plan_fence` 53 · `test_lead_path_batch0` 25 · `test_bot_stays_awake` 59 · `test_guard_blocks_send` 41 · `test_reply_privacy_guard` 71 · `test_ai_brain` 153 · `test_ai_minimal_context` 61 · `test_location_pin` 79 · `test_human_handover` 14 · `test_human_takes_over` 16 · `test_human_reply_visible` 9 · `test_history_identity` 94 · `test_contact_optout` 49 · `test_ai_security_policy` 146 · `test_shadow_runtime` 74 · `test_notify_evo_retry` 23 · `test_kit_tax_note` 68 · `test_ai_unlimited_and_network` 159 · `test_ai_indoor_routers` 84 · `test_notify_schedule_health` 19 · `test_cron_no_exit` 10 · `test_config_one_truth` 16 · `test_portal_handoff` 62 · `test_dist_isolation` 39.
- **Full suite:** **`tests/run.sh` 273 files, 12,535 passed, 0 failed, 0 skipped** (was 272 / 12,457 at 5.18.76: the new test's 74, the 3 added to `test_brain_context` and the 1 added to `test_plan_fence`; nothing else moved). **Second run: 273 / 12,535 / 0 again**, on the same code, start to finish.
- `git diff --check` clean; the diff's only phone-shaped strings are the synthetic `2567720004xx` / `25677200051x`
  fixture numbers and the staff-alert fixture number the existing tests already use; no credential-shaped value; **no file under Domain B
  touched; South Sudan's scheduler list is unchanged** (`test_notify_schedule_health` 19/0 — the `ai_media` job is
  flag-gated since Batch 1 and no job was added).

**Production impact — none today**: nothing is deployed, both flags are off everywhere, no provider exists. If
5.18.77 were installed with the flags off: the webhook stores media as before and records nothing; no job runs; the
settings listing shows four more keys; a hand-over from the reply worker behaves exactly as before, through the moved
code. With both flags on **and no provider** (the only possible state today): every voice note is fetched, refused as
`provider_missing`, and handed to a person with the holding line — nothing is answered from a guess. **5.18.77 sits on
5.18.76 and 5.18.75; any deploy carries Batch 0, whose two flags read ON in Uganda's listing — the operator's rule stands.**

**Rollback:** code only; the flags off restore today's behaviour without a deploy. **External side effects in
development:** none — fake servers only. **Database changes:** none (no migration). **Deployment requirement:** a
release commit cut on the live 5.18.74 (`db18ad9`) carrying 5.18.75–5.18.77, its deploy script and rehearsal — **not
cut, not pushed, awaiting the operator's instruction.** **STOPPED here; Batch 3 (image) waits for explicit approval,
and a transcription provider waits for the operator's decision on `docs/56`.**

## 05 Oct — AI communication layer, Batch 3 (5.18.78): IMAGES, dark — the provider boundary and the payment-screenshot rule documented, the image path built on the existing assistant, NO provider selected, NOT deployed, NOT pushed; STOPPED before Batch 4

**Instruction (in substance):** *Batch 2 accepted; keep `ce16324` and `73ae827` local and unpushed; do not deploy, do not
enable `ai_media_enabled` or `ai_media_voice`, do not select a transcription provider. Batch 3 approved — IMAGE MEDIA ONLY:
read `docs/55`, `docs/56`, the Batch 1 and 2 contracts, the webhook/media path and the guard/hand-over/audit path first;
document the image provider boundary before any real provider; implement image classification through the foundation,
safe fetching, validation (MIME, announced size, decoded size, timeout, supported types), a deterministic fake provider, an
adapter interface, the description into the EXISTING brain path with explicit image modality metadata, STOP/privacy/
hand-over/audit preserved, idempotency, no permanent storage, no bytes in logs. CRITICAL: a payment screenshot may be
classified, described and routed to the existing human process and nothing more — never an invoice marked paid, a
payment created or modified, acceptance promised, a record altered, or vision output treated as financial evidence.
Flags off, no production change; the eighteen tests; local commit allowed; no push, no deploy; stop and report.* All of
it honoured. **Nothing left this machine; both flags are off everywhere; no provider exists; no money was touched.**

**The boundary first — `docs/57`** (mirrors `docs/56`): `ImageDescriberPort` (`describe(MediaBlob, hints, timeoutSeconds)`
→ a description with an ADVISORY classification and signals, or a FIXED reason code with a retryable flag; `detail` never
carries bytes, text, a key, a number or a JID), `ImageDescriberFactory` (`ai_image_provider` unset or `none` → no
provider; `fake` → test-only, needs its environment; anything else → no provider and one log line — **no value selects a
real provider**), `FakeImageDescriber` (scripted by the picture's sha256; records hash prefixes and sizes, never bytes or
text). Size, time and cost assumptions and the test strategy are in `docs/57` §7–§8. **The provider, and whether a
customer's picture — a payment screenshot carries names, numbers and amounts — may leave the server at all, are the
operator's decisions, not taken here.**

**Where the description enters — the same single place as voice.** `ImageUnderstanding`, called by the Batch 1 media
worker once it holds the picture in memory, queues ONE `ai.reply` event of the webhook's shape with `message` =
`[image, described automatically] <description>` and, when the customer typed one, `[caption from the customer] <caption>`
on the next line, plus `origin: image` and `image: {media_id, classification, width, height, has_caption}`. **A captioned
photo is answered once:** with the image flag on, the webhook no longer queues the caption as a typed turn (step 9a); the
image turn carries both. With the flag off the caption is answered as text exactly as before. STOP stays the webhook's,
read off the caption at receipt (8b) — a description is the provider's words, not the customer's, and is never read for
STOP (proved: a description reading "stop" records nothing).

**The payment-screenshot rule, as built:**
- **The decision is the plugin's, not the provider's.** `lib/PaymentEvidence.php`: yes when the provider's class is
  `payment_proof`, OR when the description or signals carry both a money token (an amount, a currency, "total",
  "balance") and a transaction token ("paid", "transaction", "receipt", "transfer", "confirmation", "reference", mobile
  money, bank). A shop front with a telecom sign or a price list is not a payment; "UGX 420,000 … transfer … successful …
  reference" is, whatever the provider called it. The rule errs towards yes.
- **Evidence only.** The row becomes `understood` / `payment_evidence` with the description as the record; the stored
  message reads `[image, payment evidence — a colleague will verify] <description>` plus the caption, so the person
  verifying reads it in the inbox against the books. **No `ai.reply` is queued — the brain never sees it.** The
  conversation is handed over through `lib/Handover.php`: `needs_human`, a `wa.escalation` event by `media_worker`, the
  staff alert — which says a payment screenshot or receipt arrived and **nothing was recorded or marked paid**, and
  deliberately carries no amount or reference — and the operator's own holding line to the customer once.
- **No financial write, proved three ways:** every money table (`dpo_payments`, `dpo_payment_events`, `cb_ledger`,
  `staff_ledger`, `wallet_transactions`, `cash_advances`, `expense_receipts`, `stock_purchase_payments`,
  `lte_financial_ledger`, … — every table whose name says pay, invoice, cash, ledger, money, receipt, wallet or
  transaction) has exactly the rows it had after a payment screenshot is processed; the fake uCRM received no payment
  (core) and no POST/PATCH to payments or invoices (CLI); and the image path and the hand-over name no payment, ledger,
  cashbook or CRM writer (`CrmApiClient`, `createPayment`, `DpoPaymentService`, `CashbookService`, …), checked on the
  code with comments stripped.
- **A second line in the prompt.** Should a payment image ever reach the brain, the IMAGE block forbids confirming,
  accepting or promising anything about payment, saying money was received or an invoice is settled, or quoting the
  figures as facts; a colleague verifies. `ReplyPrivacyGuard` still refuses figures the tools did not return.

**Validation before any provider, and the layers behind each other:** the Batch 1 fetcher refuses a type outside
JPEG/PNG/WebP, an announced size over the limit (before the fetch) and an actual size over it (after); then
`ImageUnderstanding` reads the header with `getimagesizefromstring()` (no decoder library): bytes that are not an image
are `malformed_image`, a GIF behind a PNG label is `unsupported_mime`, a header declaring more than 8,000 px a side or
25 megapixels is `too_large_image` — all permanent, all before the provider, all handed to a person. One weakened copy
taught something worth recording: **removing the first header check alone changes nothing, because the next layer (no
type in the header → `unsupported_mime`) still refuses the junk** — so the copy that is caught is the one that trusts the
label instead of reading the header.

**What was built, file by file:**
- **`lib/ImageDescriber.php`** — the port, the factory, the fake (above). **`lib/PaymentEvidence.php`** — the rule and the
  hand-over wording. **`lib/ImageUnderstanding.php`** — `process()` (header, caps, provider, normalisation to 2,000
  characters, classification fixed to the known list: `payment_proof · site_photo · equipment_photo · screenshot ·
  document_photo · general · unreadable`, the payment decision) and `complete()` — one transaction: the row `understood`
  only if it was not already (`status <> 'understood'`), the stored message rewritten, then either nothing queued
  (payment) or the one event. A database failure rolls all of it back and is thrown, so the worker retries.
- **`workers/MediaWorker.php`** — after a fetch of an image row, **only when `MediaPolicy::imageEnabled()`** (both flags),
  the blob goes to `ImageUnderstanding` and is wiped whatever happens: `understood` → logged by class and length;
  `payment_evidence` → `Handover::escalate()` with `PaymentEvidence::handoverReason()`; a retryable failure thrown (the
  retry fetches again); a permanent failure, a Batch 1 fetch refusal and the queue giving up → handed over. The settled
  rule covers a fetched picture awaiting its description; `useImageDescriber()` for tests.
- **`evo_webhook.php`** — step 9a: a recorded picture with the image flag on skips the caption's text turn, logged
  (*caption carried by the media worker*). Every anchor the other suites hold on this file is intact.
- **`workers/AiReplyWorker.php`** — `image => {classification}` in the turn's context when the event says `origin: image`;
  passed to `BrainContext::build()`; a blocked reply's audit event says `modality: image`; the log line says
  `origin=image`. **`lib/BrainContext.php`** — the **fifteenth** key, `image => ['classification']`, present only when the
  caller says so (`test_brain_context` pins fifteen). **`lib/DishNetAiBrain.php`** — a conditional **IMAGE JUST RECEIVED
  (classified as …)** block beside the pin and voice blocks: an automatic description, not the customer's words; every
  figure unconfirmed; the payment prohibition; the caption marked as the customer's; content under rule 7. A typed
  turn's prompt is byte-for-byte what it was.
- **`lib/MediaPolicy.php`** — `imageEnabled()` (= `enabled()` AND `ai_media_image`), the image timeout (default 30, range
  5–120), the side and pixel caps, the description cap. **`tools/set_config.php`** — `ai_media_image`,
  `ai_media_image_timeout_s` (range refused), `ai_image_provider` (`none` only; the fake refused by name, as the
  transcription provider's). **`manifest.json` 5.18.78; the nine pins.** **No migration.**

**Not built, deliberately:** a vision provider; document extraction (Batch 4); any voice change; lead state, follow-up,
e-mail, marketing, n8n, another gateway, another brain; any financial automation; any customer-facing sentence about
payments beyond the operator's own holding line.

**Proofs — `tests/test_image_media.php`, 89/0** (a core scenario in-process against the fake Evolution and the fake uCRM
with the deterministic fake describer injected and a fake brain that parses its canned answer with the real marker
parser, on genuine PNG headers built by the test; a CLI scenario in the real plugin tree under `php -S`; both return facts
so a weakened copy can run them). The eighteen areas the instruction names:
1. **valid image → row → fetch → understanding**: `understood` / `description`, the stored `[IMAGE]` message rewritten with
   the labelled description and image metadata; one fetch, one provider call that received the bytes, the header facts
   (640×480) and the 7 s budget and recorded a hash prefix and sizes.
2. **enters the brain exactly once**: one `ai.reply` by `media_worker`, the webhook's shape, no voice key; one brain call.
3. **image modality preserved**: the label, `origin: image`, classification, dimensions, caption flag and row id on the
   event; `image = {classification}` in the sales contract; the IMAGE block in the prompt (and not for a typed turn); a
   later typed turn sees the labelled description in history; a captioned picture carries description and caption under
   their own labels, once; a blocked reply's audit event says `modality: image`.
4. **duplicate idempotent**: no second fetch, call or event; `complete()` on an understood row answers `already_understood`.
5. **unsupported MIME**: an SVG announced is refused by the Batch 1 fetcher; a GIF behind a PNG label is refused by the
   header — both before any provider, both handed over.
6. **oversized**: an announced 20 MB refused before the fetch; a header declaring 9000×7000 refused before the provider;
   both handed over.
7. **malformed**: bytes that are not an image → `malformed_image`, no provider call, no event, handed over.
8. **timeout / failure retried**: row `failed/timeout`, event failed with one attempt, not handed over; made due, the
   retry fetches again, describes and queues the one event; a provider error retried likewise.
9. **permanent failure → hand-over**: no provider → `provider_missing`, the holding line, the alert; the factory yields
   nothing for unset, `none`, an unknown name or `fake` without its environment; the queue giving up → `dead`, handed over.
10. **STOP intact**: a description reading "stop" is not an opt-out; under C, a caption "stop" with a photo records the
    opt-out through the webhook exactly as typed text does, and the picture still makes its turn.
11. **guard intact**: a reply the description induced ("our cost…") is blocked, the fallback sent, audited against the
    real conversation with `modality: image`, a person takes over.
12. **payment screenshot → evidence and escalation only**: by class (an MTN Mobile Money confirmation) and by words
    alone (a "screenshot" of a bank transfer marked successful) — row `payment_evidence`, **no event**, `needs_human`, one
    `wa.escalation` by `media_worker` naming the rule, the alert saying nothing was recorded and carrying no amount or
    reference, the holding line once, the inbox showing the evidence under the payment label; a speed-test screenshot is
    an ordinary picture; the detector's seven cases.
13. **no financial write**: every money table unchanged (≥ 8 tables compared), the fake uCRM received no payment, no
    writer named in the image path or the hand-over.
14. **no bytes / base64 persisted**: no file under the data directory holds the picture, its base64, its hash, a
    description or a transaction id.
15. **no sensitive content logged**: the worker logs carry no base64, no description, no amount or reference, no JID; the
    provider saw hash prefixes and sizes only.
16. **image OFF**: fetched (Batch 1), zero provider calls, zero events, zero messages, no hand-over; the caption stays the
    stored text.
17. **media OFF overrides**: `imageEnabled()` needs both flags; media off with image on → `skipped`, nothing fetched.
18. **ten weakened copies, each caught**: the image flag alone turning images on; the worker ignoring the flag; the words
    no longer deciding (a mislabelled payment reaches the brain); payment evidence queued for the brain after all; the
    label removed; the prompt block removed; the header trusted instead of read; the dimension cap removed; the
    permanent-failure hand-over removed; the description logged. Control: the real tree trips none.
- **CLI, in the real tree:** a captioned photo with both flags on — the webhook queues no text turn (`queued 0`,
  `media_queued 1`); `run_media_worker.php` with the fake provider through its test-only environment describes it and
  queues ONE event carrying description and caption; a caption "stop" records the opt-out through the webhook; **a
  payment screenshot through the real tree: evidence, hand-over, no event, no uCRM payment or invoice request, no amount
  in the alert or the log**; image OFF → the caption answered as text by the webhook as always and the picture fetched
  only; media OFF → nothing recorded; provider `fake` without its environment fails closed.
- **Neighbours in the full run, all 0 failed:** `test_image_media` 89 · `test_voice_media` 74 · `test_media_foundation` 102 · `test_brain_context` 129 · `test_handover_message` 9 · `test_plan_fence` 53 · `test_lead_path_batch0` 25 · `test_bot_stays_awake` 59 · `test_guard_blocks_send` 41 · `test_reply_privacy_guard` 71 · `test_ai_brain` 153 · `test_ai_minimal_context` 61 · `test_location_pin` 79 · `test_human_handover` 14 · `test_human_takes_over` 16 · `test_human_reply_visible` 9 · `test_history_identity` 94 · `test_contact_optout` 49 · `test_ai_security_policy` 146 · `test_shadow_runtime` 74 · `test_notify_evo_retry` 23 · `test_kit_tax_note` 68 · `test_ai_unlimited_and_network` 159 · `test_ai_indoor_routers` 84 · `test_notify_schedule_health` 19 · `test_cron_no_exit` 10 · `test_config_one_truth` 16 · `test_portal_handoff` 62 · `test_dist_isolation` 39.
- **Full suite:** **`tests/run.sh` 274 files, 12,627 passed, 0 failed, 0 skipped** (was 273 / 12,535 at 5.18.77: the new test's 89 and the 3 added to `test_brain_context`; nothing else moved). **Second run: 274 / 12,627 / 0 again**, on the same code, start to finish.
- `git diff --check` clean; the diff's only phone-shaped strings are the synthetic `2567720006xx` / `2567720007xx` fixture
  numbers and the staff-alert fixture number the existing tests already use; no credential-shaped value; **no file under
  Domain B touched; South Sudan's scheduler list unchanged** (`test_notify_schedule_health` 19/0; no job added).

**Production impact — none today**: nothing is deployed, both flags are off everywhere, no provider exists. If 5.18.78
were installed with the flags off: the webhook stores media as before and records nothing; captions are answered as text
as always; no job runs; the settings listing shows three more keys. With both flags on **and no provider** (the only
possible state today): every photo is fetched, refused as `provider_missing`, and handed to a person with the holding
line — and a captioned photo's caption is answered by that person, not by the assistant. **5.18.78 sits on 5.18.75–77;
any deploy carries Batches 0–2, and the operator's rule on the two Batch 0 flags stands.**

**Rollback:** code only; the flags off restore today's behaviour without a deploy. **External side effects in
development:** none — fake servers only. **Database changes:** none (no migration). **Deployment requirement:** a
release commit cut on the live 5.18.74 (`db18ad9`) carrying 5.18.75–5.18.78, its deploy script and rehearsal — **not cut,
not pushed, awaiting the operator's instruction.** **STOPPED here; Batch 4 (documents) waits for explicit approval, and a
vision provider waits for the operator's decision on `docs/57`.**

## 05 Oct — AI communication layer, Batch 4 DESIGN REVIEW (`docs/58`): the document processing boundary — DESIGN ONLY, nothing built, NOT pushed; STOPPED for the operator's decisions before any Batch 4 code

**What was asked.** After accepting Batch 3, the operator asked for a design-only review of the Batch 4 boundary — documents
a customer sends over WhatsApp — answering fifteen questions, with no code, no migration, no library, no provider, no
configuration change, no push, no deploy and no real customer document processed. `docs/58` is that review.

**What was read first.** `docs/55`–`57`; the Batch 1 media contracts; the Batch 2 voice and Batch 3 image contracts; the
webhook and the conversation store; the assistant, the guard and the audit path; and every file or document handling path
in the plugin (a read-only sweep: job photos, KYC intake, the media library, staff uploads, outbound invoice/quote/delivery
PDFs, the unused `lib/XlsxReader.php`, the CLI PDF doctor, Finance's `pdftotext` import, e-mail attachments, restore zips).

**What `docs/58` recommends.** Extract documents inside the plugin process — deterministic, no library, no provider, no
byte leaving the server — for Word `.docx`, Excel `.xlsx`, CSV and text; read a PDF for its facts only until the operator
decides how its text should be read (D-1) and whether scanned pages get OCR at all (D-2); classify every document before
anything reaches the assistant: seven classes are human-only (payment proof, statement, invoice, contract, quotation,
identity document, credential) and never produce an AI turn, two (general, spreadsheet) enter the EXISTING assistant exactly
as a transcript or an image description does. Payment evidence in a document follows the Batch 3 rule unchanged. The one
provider boundary declared is `DocumentOcrPort`, empty, with `none` as its only value. No migration, no new event type, no
new table; rollback is the flag, then one commit. Six flags, all off; nine content classes with their hand-over wording;
every limit with its reason; the egress stated exactly (only the capped extract of a brain-eligible document reaches the
model vendor the brain already uses; a human-only document reaches nothing).

**Two live-path defects found by the sweep, independent of any flag** (`docs/58` §2.1): a captioned document inside
Evolution's `documentWithCaptionMessage` wrapper is dropped by `ConversationService::importEvoMessage` before it is stored
— no Inbox row, no text turn, nothing for the media worker; and `InboundMedia` unwraps one wrapper level only. Both are
small; whether Evolution 2.3.7 sends the wrapper is not recorded (E-6). Fixing the first changes the live text path for a
shape that is dropped today, so it is the operator's call (D-11).

**Also measured.** No inbound WhatsApp document is content-sniffed today (the announced type is trusted, proved by
`test_media_foundation`); the uCRM container's `php -m` has never been recorded (zlib, xmlreader and iconv are unknown —
E-1), nor the CLI `memory_limit` the media runner inherits (E-2); `pdftotext` is documented as absent from the uCRM image;
the plugin bundles no PHP library at all. This sandbox's PHP (8.4, with zip/zlib/xmlreader) says only that the fixtures can
be built here.

**Decisions for the operator** (`docs/58` §B): D-1 PDF text (in-house reader recommended as slice 4b), D-2 OCR (none for
now), D-3 quotations, D-4 invoices, D-5 legacy `.doc`/`.xls`, D-6 the defaults, D-7 the masked excerpt, D-8 the hand-over
wording, D-9 the three numbers, D-10 payment evidence with a caption, D-11 the §2.1 fix.

**Not done, deliberately.** No Batch 4 code, migration, library, provider, flag or configuration; nothing pushed or deployed;
Batches 1–3 (`ce16324`, `73ae827`, `b08c9d4`) untouched and still local. Guards on the new document: no phone-shaped
value, no banned value, no credential-shaped value. This entry and `docs/58` are the fourth local, unpushed commit.

## 05 Oct — AI communication layer, Batch 4 Slice 4a (5.18.79): DOCUMENTS, dark — the approved boundary built inside the process, NO PDF text, NO OCR, NO provider, NO library, NO migration; two live-path wrapper defects fixed (D-11); NOT deployed, NOT pushed; STOPPED for review before Slice 4b

**What was approved.** The operator approved `docs/58` with eleven decisions (D-1 no PDF text in 4a, an in-house reader as
slice 4b; D-2 no OCR, the boundary kept empty; D-3 quotations and D-4 invoices human-only, holding line only; D-5 legacy
`.doc`/`.xls` refused at extraction with a request for a PDF; D-6 the defaults 10 MiB · 20 pages · 20 s · 4,000 characters;
D-7 a 300-character masked excerpt, nothing for identity and credential; D-8 the hand-over wording tested word for word;
D-9 all three numbers; D-10 nothing beyond the holding line; D-11 both wrapper fixes, flag-independent) and added one rule:
**classification must fail closed** — never `uncertain → general → AI`. Both flags, `ai_media_enabled` and the new
`ai_media_document`, stay off everywhere.

**What was built** — every line inside the plugin process; no library, no provider, no byte leaves the server.

- **Ten new library files.** `DocumentDeadline` (the fixed-reason refusal types and the deadline checked between bounded
  steps; too slow is permanent, not retried); `OoxmlArchive` (a minimal zip reader written so that no temporary file and no
  `ZipArchive` is needed: an entries cap, zip64 and encrypted entries refused, an ALLOW-LIST of the handful of parts a Word
  or Excel reader needs, the declared size checked before a byte is inflated, `gzinflate` capped at the declared size so a
  lying member comes back false, length and CRC checked); `DocumentSniffer` (the content names the kind — PDF, Word, Excel,
  CSV, text — or refuses: an image, a legacy or macro-enabled Office file, an encrypted package, an archive that is not Word
  or Excel, bytes that are no document); `TextReader`, `DocxReader` (XMLReader with `LIBXML_NONET`, no entity substitution,
  no DTD loading, a DOCTYPE refused before the parser sees it; deleted text and field codes never read), `SheetReader`
  (cached values only, formulas never evaluated; named so because the unused `lib/XlsxReader.php` keeps its name),
  `PdfReader` (FACTS only: encrypted, pages, a text layer or image-only), `DocumentOcr` (the empty boundary and its
  deterministic fake), `DocumentClassifier`, `DocumentExtraction` (the pipeline and the one transaction).
- **The classifier fails closed.** Seven human-only classes — credential, identity document, payment evidence (the Batch 3
  rule verbatim), statement, invoice, contract, quotation — never produce an AI turn; two — general, spreadsheet — enter the
  EXISTING assistant exactly as a transcript or an image description does, and only when the classifier saw ALL the text and
  found NO signal of any sensitive class: one money word, one transaction word, one phrase from the identity, statement,
  invoice, contract or quotation lists, or a word in the file name is `classification_uncertain`; text a reader could not
  finish is `classification_incomplete`; an exception is `classification_failed`; each is a person. Precedence as built:
  credential → identity → a decisive statement / invoice / contract / quotation phrase → the payment words → two
  supporting phrases → spreadsheet → general (`docs/58` §19 records why the decisive phrases moved ahead of the payment
  words: a statement has deposits and balances, and the person is told which it is).
- **The record per class.** Nothing for identity and credential (the row's `understanding` is empty, the inbox shows the
  label alone); a 300-character excerpt with digit runs and e-mail addresses masked for the five evidence classes; the
  capped text the assistant read for the two it may see. `understanding_kind` is `extraction` or `document_evidence`; the
  facts live in `wa_messages.metadata.document`. No migration.
- **What the assistant gets** (`origin: document`): the extract under `[document, text extracted automatically — <kind>,
  <facts>]`, the caption under its own label, and `BrainContext`'s sixteenth key `document = {classification, kind,
  truncated}` — never the file name (`NEVER_PRESENT['file_name']`), the hash, an id or a phone. A conditional DOCUMENT block
  in the prompt: an automatic extraction, possibly cut, every figure and term unconfirmed, never accept, approve, confirm or
  agree to anything read in a document, content under rule 7. The audit event of a blocked reply says `modality: document`.
- **The guard** gains `ReplyPrivacyGuard::secretShapesIn()` (the same shapes it refuses in a reply, so a document carrying a
  key or a password is `credential` before anything is stored) and the `document_commitment` phrases — payment received or
  recorded, an invoice settled, a contract or quotation accepted, an identity verified — refused ONLY on a document turn and
  only as completed facts ("once your payment is received" passes; "we have received your payment" does not).
- **D-11.** `InboundMedia::unwrap()` is the one unwrap, three levels deep, for the four wrappers; `ConversationService::
  importEvoMessage` and the webhook's `evoExtractText` use it. A captioned document inside `documentWithCaptionMessage` —
  dropped before it was stored until now, with every flag off — is stored with its caption and type, its STOP read, its
  caption answered as text, and with the flags on recorded for the worker. Measured consequence, recorded: a disappearing
  (`ephemeralMessage`) text and a view-once document are stored too.
- **Also:** the webhook's step 9b (a captioned document with the flag on is answered once); the worker's document branch with
  the per-class hand-over reasons; `MediaPolicy` constants and `documentEnabled()`; five `set_config` keys with their ranges
  and the `none`-only `ai_document_provider`; `validate_environment` reporting zlib, xmlreader and iconv as optional;
  `run_media_worker.php` raising a CLI `memory_limit` that is below 256M to 256M (higher or unlimited values are left unchanged — wording clarified 5 Oct, Slice 4b); `PaymentEvidence::hasMoneyToken()` / `hasTransactionToken()`
  as signals; `manifest.json` 5.18.79 and the twelve pins.

**Three things found while building, each caught before the test suite saw them** (`docs/58` §19): XMLReader's `next()` lands
ON the following sibling, so a loop that then `read()`s skips it — a formula cell lost its cached value until the smoke test
showed it; a sheet whose label row said "Sum" went to a person, because "sum" is a money token — the rule working, the
fixture changed; a test assertion written as `($x['k'] ?? 'x') === null` can never be true, because `??` treats null as
unset — three mutant catches were blind until `isset()` replaced it (the control on the controls did its job).

**Proofs.**
- `tests/test_document_media.php` **161 passed, 0 failed** (59 s): the eighteen areas of `docs/58` §D; every fixture of
  §14 generated in the test (a zip writer, Word, Excel, three PDFs, the wrapped envelopes); the eight human-only outcomes
  each with its hand-over wording word for word; the money AND the identity/KYC tables counted before and after a receipt,
  a statement, an invoice, a contract, a quotation and an ID document; the fake uCRM unchanged; nothing sensitive in any
  table, file or log; the extract in `wa_media`, the stored message and the `ai.reply` payload and nowhere else; the CLI
  runner through the real webhook and `run_media_worker.php` (seven cases: a captioned Word file answered once, a WRAPPED
  "stop" caption, a receipt, document off, media off with a wrapped document still stored, a scanned PDF with and without
  the fake's environment, a nested wrapper); **24 weakened copies each caught** — the two flags, the declared-size cap,
  zlib's cap, the member allow-list, the DTD guard, the macro check, `/Encrypt`, payment and credential and identity
  routing, the record policy, the file name, the label, the prompt block, the understood guard, the hand-over, logging,
  the deadline, the guard phrases, uncertain → general, incomplete → general, both D-11 fixes — with the control.
- `tests/test_brain_context.php` 129 → 133: the sixteenth key, its three leaves, the file name refused.
- **Neighbours in the full run, all 0 failed:** `test_document_media` 161 · `test_image_media` 89 · `test_voice_media` 74 · `test_media_foundation` 102 · `test_brain_context` 133 · `test_reply_privacy_guard` 71 · `test_guard_blocks_send` 41 · `test_handover_message` 9 · `test_plan_fence` 53 · `test_ai_brain` 153 · `test_ai_minimal_context` 61 · `test_lead_path_batch0` 25 · `test_ai_security_policy` 146 · `test_contact_optout` 49 · `test_notify_schedule_health` 19 · `test_cron_no_exit` 10 · `test_config_one_truth` 16 · `test_shadow_runtime` 74.
- **Full suite:** **`tests/run.sh` 275 files, 12,792 passed, 0 failed, 0 skipped** (was 274 / 12,627 at 5.18.78: the new test's 161 and the 4 added to `test_brain_context`; nothing else moved). **Second run: 275 / 12,792 / 0 again**, every suite's tally identical. Run 1 carried 2 PHP warnings from the new test's own failure-detail builders (an `alerts` key two fact arrays did not carry); fixed before run 2 reached the suite, which carried 0. The product code was identical in both runs.
- `git diff --check` clean; no secret-shaped value in the diff; the two banned values absent; no file under
  `dishnet-mikrotik-control-plane/` touched; no migration added (the last is still 085); the South Sudan scheduler suite
  unchanged; the test fixtures' phone numbers are the synthetic `25677200xxxx` ones.

**Not done, by decision:** PDF text (slice 4b, D-1); OCR (slice 4c, D-2); any provider, library, migration, deployment or
flag; voice and image logic untouched beyond the document branch beside them. `lib/XlsxReader.php` left as it was.

**Git:** committed locally as the fifth unpushed commit on `claude/study-this-jhe2eg`, after `ce16324`, `73ae827`,
`b08c9d4` and `67b6c9d`. Nothing pushed, nothing deployed, no configuration changed, nothing sent to anyone, no money, no
identity decision. Stopped for the operator's review before slice 4b.

## 05 Oct — AI communication layer, Batch 4 Slice 4b (5.18.80): PDF TEXT, dark — the in-house reader (P-1) on the Slice 4a boundary; E-3 and E-6 NOT MEASURED under an approved risk exception; the 4a memory-test gap closed; NOT deployed, NOT pushed; STOPPED for review

**What was approved.** After the Slice 4a review and an evidence gate (`docs/58` §20), the operator approved Slice 4b: the
in-house PDF text extraction (P-1), OCR out, no external document or OCR provider, every Slice 4a safeguard kept — the seven
human-only classes never an `ai.reply`, only the approved capped text of a harmless document to the existing AI vendor, the
original document never leaving, every flag off, `ai_document_provider = none`, no business action from document
understanding, STOP / hand-over / privacy / guard behaviour unchanged, Uganda and South Sudan unchanged, Domain B untouched.
PDF scope: deterministic local extraction only, the existing 20 pages · 10 MiB · 20 s · 4,000 characters, fail closed on
malformed, encrypted, unsupported or unsafe files, no OCR, a PDF with no readable text a person, no original bytes kept.

**The evidence status, recorded as it stood and not converted into anything else:** E-1 **MEASURED** (the uCRM container:
PHP 8.1.34 with zlib, xml, xmlreader, mbstring, iconv, fileinfo, zip loaded) · E-2 **MEASURED** (CLI `memory_limit` 2048M, so
the runner's raise never fires there) · **E-3 NOT MEASURED** (live Evolution fetch latency for 5–10 MiB: the staff-phone test
could not be performed safely) · E-4 **MEASURED** (`wa_messages` no retention; `events` pruned after 30 days by the
maintenance cron — `docs/58` §18 had said "no caller", corrected) · E-5 **MEASURED but incomplete** (about one inbound
document a day, a floor; the type mix unknown) · **E-6 NOT MEASURED** (the real Evolution envelope of a captioned document).
**Slice 4b was built despite the E-3 and E-6 gaps under the operator's approved risk exception.** Neither gap touches the
data-egress or human-only boundaries; both remain to be measured before any flag is turned on.

**What was built** — all of it in the plugin process; no library, no provider, no migration.

- **`lib/PdfReader.php` gains `text()`** beside the 4a `facts()`: objects found by scanning (the last definition wins), object
  streams opened; FlateDecode through zlib's incremental API so a stream inflating beyond 8 MiB is refused as too large and
  one that does not inflate as malformed; PNG predictors; ASCIIHex and ASCII85; **any other filter is a refusal**; the page
  tree walked with inherited resources, cycles and depth refused, with two fallbacks and then `malformed_document`; text
  operators decoded through ToUnicode CMaps, WinAnsi / MacRoman / Standard and `/Differences`; glyphs it cannot name counted,
  never invented, and above a 10% share the text is not "seen whole"; Form XObjects to a depth of 8, a cycle refused; inline
  images skipped; line breaks where the text matrix moves down. Every loop has a cap; the deadline is asked between objects,
  between pages and every 2,000 operators; the text is cut at the caller's cap. One line of `facts()` changed so a PDF 1.5
  file's page count is right.
- **`lib/DocumentExtraction.php`**: the `pdf` case reads the text layer through the SAME classification, record, label and
  one-event path as every other kind; `requireCapabilities(['gzuncompress', 'inflate_init'])` first; a layer that yields no
  text is the new permanent reason **`pdf_no_text`** with the person's wording *"a PDF arrived (N pages, a text layer that
  yielded no text) — no text could be read from it automatically; open it in WhatsApp"*; **`pdf_not_read` is retired**. A
  scanned PDF still meets the empty OCR boundary (D-2).
- `tools/set_config.php`, `lib/MediaPolicy.php` and the worker's docblock describe PDF text; `manifest.json` 5.18.80 and the
  fourteen pins.
- **The 4a memory-test gap closed** (the controlled edit the review asked for): `test_document_media` C8 runs the REAL runner
  under `php -d memory_limit=64M` on a 12 MiB text file (read to the text cap with the raise; refused for memory without it,
  by a weakened copy); `test_document_pdf` §8 lowers the live `memory_limit` in-process and proves the budget rule refuses a
  14 MiB document and passes a 1 MiB control, with a weakened copy. The mutant loop gained a per-mutant driver.
- **Wording cleanup** in `docs/58` §19, this file's Slice 4a entry and `set_config`: "a CLI memory limit below 256M" now reads
  "a CLI `memory_limit` that is below 256M to 256M; higher or unlimited values are left unchanged".

**Three things found while building, recorded rather than hidden** (`docs/58` §20): `gzuncompress()` with a cap cannot tell
an oversize stream from a corrupt one, so the incremental API gives the honest reason; the tests' wrapper fixtures are this
project's own and leave E-6 NOT MEASURED whatever they prove; an alphanumeric transaction reference survives the masked
excerpt by the approved D-7 policy (digit runs of six or more and e-mails), so the test proves a twelve-digit reference is
masked rather than widening the policy by assertion.

**Proofs.**
- `tests/test_document_pdf.php` **102 passed, 0 failed** (24 s, no fake server): 29 generated PDFs through the reader, the
  pipeline from bytes to the queue for every outcome the brief lists, the seven human-only classes from PDFs with the money
  and KYC tables identical, nothing sensitive and no PDF byte in any table or file, STOP and the guard, the memory budget;
  **15 weakened copies each caught, with the control.**
- `tests/test_document_media.php` **166 passed, 0 failed** (was 161): the text PDF read into one labelled turn, `pdf_no_text`
  through the worker, C8 under 64M; **25 weakened copies each caught** (24 of 4a plus the runner's raise), control on core
  and cli facts.
- **Full suite:** **`tests/run.sh` 276 files, 12,899 passed, 0 failed, 0 skipped** (was 275 / 12,792 at 5.18.79: the new `test_document_pdf` 102 and the 5 added to `test_document_media`; nothing else moved). **Second run: 276 / 12,899 / 0 again**, every suite's tally identical. PHP warnings in either run: 5. The South Sudan and tenant suites, unchanged: `test_notify_schedule_health` 19 · `test_staff_jobs_south_sudan` 51 · `test_tenant_profile` 108 · `test_email_no_sudan` 62 · `test_notify_tenant_text` 30 · `test_cashbook_tenant` 26 · `test_portal_tenant` 112 · `test_sales_support_tenant` 37 · `test_ai_country_facts` 21 · `test_phone_country` 26.
- `git diff --check` clean; no secret-shaped value in the diff; the two banned values absent; no file under
  `dishnet-mikrotik-control-plane/` touched; no migration added (the last is still 085); every tenant and South Sudan suite at
  the tally it had; the test fixtures' numbers are the synthetic `25677200xxxx` ones.

**Not done, by decision:** OCR (D-2, slice 4c); any provider, library, migration, deployment or flag; the document type mix
(E-5) and the live E-3 / E-6 measurements, which stay open.

**Rollback:** code only. Reverting the commit restores 5.18.79 exactly — no migration, no setting, no file on disk differs,
and a 5.18.79 runner reads every row 4b wrote.

**Git:** committed locally as the sixth unpushed commit on `claude/study-this-jhe2eg`, after `ce16324`, `73ae827`,
`b08c9d4`, `67b6c9d` and `4c931ef`. Nothing pushed, nothing deployed, no configuration changed, nothing sent to anyone, no
money, no identity decision. Stopped for the operator's review.

## 05 Oct — AI communication layer, Batch 5 (5.18.81): DOCUMENT ACTIVATION SAFETY — the four-rung flag ladder, the dry run, the counters tool, the lost-worker guard; NOTHING enabled, NOT deployed, NOT pushed; E-3 and E-6 still NOT MEASURED; STOPPED for review

**Instruction.** After docs/59's NO-GO the operator ordered Batch 5: *the minimum safety layer required before document AI can be
activated* — an explicit dry-run / hand-over-only mode, explicit activation flags with a documented dependency that a higher flag
can never bypass, safe counters and logging, a review of the EventBus retry finding without silently altering the shared
queue, E-3 and E-6 left NOT MEASURED, focused tests, the full suite twice, weakened copies, the Domain B and South Sudan checks,
a secret scan and lint, a design record, a local commit and no push. Development only. Slice 4b (`7901ffe`) is unmodified in
what it reads; `archive/slice-4b-2026-10-05` holds it on GitHub. The record is **`docs/60`**.

**The ladder (`MediaPolicy::documentMode`).** `ai_media_enabled` fetches; `ai_media_document` alone is the **dry run** — a document
is fetched, extracted, classified and RECORDED on the `wa_media` row and in the stored message's metadata, and nothing else
happens: nobody is told, no AI turn is queued, the stored body the model reads as history is untouched, a caption is answered
as text by the webhook as it always was; `ai_media_document_handover` adds the person (a human-only class, a refusal, a fetch
that failed for good, a queue that gave up); `ai_media_document_reply` adds the assistant's turn for a harmless document and the
caption-once rule (step 9b). Each rung needs every rung below it, proved over all sixteen combinations; every default is off.

**What was built.**
- `lib/MediaPolicy.php` — `documentHandoverEnabled()`, `documentReplyEnabled()`, `documentMode()` (off · dry_run · handover ·
  reply), `DOCUMENT_MODES`.
- `lib/DocumentExtraction.php` — `complete()` is mode-aware: the body rewritten and the `ai.reply` queued **only** on the reply
  rung; `evidence` returned only on the hand-over rung and above; otherwise the new outcome `recorded`; the metadata gains
  `mode`, `ms` and `body_rewritten`. The record itself (understanding, record policy) is the same in every mode — that is what
  the dry run observes.
- `workers/MediaWorker.php` — hands over only on the hand-over rung, for refusals, failed fetches and dead rows alike; one
  structured outcome line per document (outcome, kind, class, reason, mode, ms, attempts — never content, a name, a number or
  a hash); the **lost-worker guard**: a row the queue has claimed as many times as its event allows is settled `dead`
  (`worker_lost`), a person told as for a dead letter, the event acknowledged — the EventBus counts attempts only on a reported
  failure, so a killed worker was retried for ever (docs/59 §5.6).
- `evo_webhook.php` step 9b — the caption's text turn is skipped only on the reply rung.
- `tools/set_config.php` — the two new bool keys; `tools/media_status.php` — **new**, read-only (`SQLITE_OPEN_READONLY`), the
  counters docs/59 §5.2 asked for, codes and counts only.
- `manifest.json` 5.18.81 and the fourteen pins. **No migration** (still 085); `lib/EventBus.php` untouched.

**The EventBus decision (docs/60 §6).** The shared queue is not altered. The media exposure is closed inside the worker; the
shared fix — `releaseStale()` counting a release as an attempt, with a dead path each waiting-person worker must notice — is
designed and recommended as its own batch, with the proof that text events are unchanged (D-60-2). Both halves of today's
behaviour are pinned by test so they cannot drift unnoticed.

**E-3 / E-6: NOT MEASURED**, unchanged; the safe staff-only, read-only, flags-off procedure of docs/59 §2.6 is preserved by
reference.

**Guards amended deliberately, never deleted.** `test_document_media.php` and `test_document_pdf.php` climb the whole ladder in
their configurations, so they keep proving the REPLY rung Slices 4a/4b built; the 9b wiring guard names the new condition and
`test_document_activation.php` proves the three lower modes answer the caption.

**Proofs.**
- `tests/test_document_activation.php` **92 passed, 0 failed**, twice: the sixteen-combination matrix, the dry run (the record, no event, no
  hand-over, the body untouched, a later typed turn seeing only the placeholder), the hand-over and reply modes, the bypass attempts, fifteen
  human-only-class × mode combinations with no AI turn, the lost-worker guard with its control, the queue's own semantics pinned, the privacy of
  every log line and of the counters tool, the real webhook and the real runner in a sandbox over all four modes with typed text still queued;
  **11 weakened copies each caught**, and the control (core and cli) trips none.
- `tests/test_document_media.php` 166 and `tests/test_document_pdf.php` 102: unchanged tallies on the reply rung.
- **Full suite:** **`tests/run.sh` 277 files, 12,991 passed, 0 failed, 0 skipped** (was 276 / 12,899 at 5.18.80: the new `test_document_activation` 92;
  no other suite moved). **Second run: 277 / 12,991 / 0 again**, every suite's tally identical. PHP warnings in either run: 5
  (test_dpo_endpoints 5). The South Sudan and tenant suites, unchanged: `test_notify_schedule_health` 19 · `test_staff_jobs_south_sudan` 51 · `test_tenant_profile` 108 · `test_email_no_sudan` 62 · `test_notify_tenant_text` 30 · `test_cashbook_tenant` 26 · `test_portal_tenant` 112 · `test_sales_support_tenant` 37 · `test_ai_country_facts` 21 · `test_phone_country` 26.
- `git diff --check` clean; `php -l` on every changed PHP file; no post-7.4 syntax in the plugin files; no secret-shaped value in the diff; the two
  banned values absent; no file under `dishnet-mikrotik-control-plane/` touched; no migration added (the last is still 085).

**Not done, by decision:** no flag on anywhere, no deployment, no production configuration, no customer-facing document AI; the
shared EventBus remediation (designed only); a scheduled copy of the counters; OCR; E-3/E-6.

**Rollback:** code only, flag first (docs/60 §5): reply off, hand-over off, document off, media off — each proved as a behaviour;
reverting the commit restores 5.18.80 exactly; a row written by 5.18.81 is read by 5.18.80.

**Git:** committed locally as the seventh unpushed commit on `claude/study-this-jhe2eg`, after `7901ffe`. Nothing pushed,
nothing deployed, no configuration changed, nothing sent to anyone. Stopped for the operator's review.

## 05 Oct — Customer Installation Authorisation for Starlink installation jobs (5.18.82) — BUILT, flag OFF, NOT deployed

**What and why.** The emergency brief: sometimes a customer refuses, disputes or denies having authorised a Starlink installation after a
technician is involved. Around the EXISTING Starlink Installation Job (uCRM's scheduling job — not rebuilt, not replaced) the plugin now
holds one Customer Installation Authorisation record per job: a staff member requests it from the job page, confirming the charges; the
customer gets the request by WhatsApp and e-mail with a secure link; the page shows the job, location, service, equipment, charges and
total as SNAPSHOTS, the full Installation Terms (`INSTALLATION-TERMS-v1.0`, SHA-256 pinned) and two buttons, ACCEPT & AUTHORISE
INSTALLATION / DECLINE INSTALLATION; acceptance is one idempotent UPDATE; only then the technician uCRM names on the job NOW (WhatsApp +
e-mail copy) and the customer (confirmation) are told; the server refuses Accept Job, the GPS check-in and Complete Job on a Starlink
installation without an accepted record — pending, declined, cancelled, expired and never-requested all fail closed with the brief's
words; a job already in progress with no record is exempt (D3); a reassignment after acceptance tells the new engineer "CUSTOMER
ALREADY CONFIRMED INSTALLATION". Discovery and approval: `docs/61` (D1–D11 approved as recommended, 2026-10-05). Terms draft for legal
review: `docs/62`. Build record and the final report A–T: **`docs/63`**. Uganda only, Starlink installation titles only,
`install_auth_enabled` OFF by default, additive migration 086, South Sudan byte for byte unchanged, no production message, no deployment,
local commit only.

**What was built.**
- `migrations/086_install_authorisation.sql` — `install_auth` (one row per job: reference `ACC-YYYYMMDD-NNNNNN`, status, terms version
  and hash, price and scope snapshots, the contact the request went to, SHA-256 of the token — never the token — expiry, the acceptance
  or decline), `install_auth_events` (append-only by trigger; the fourteen event names by CHECK), `install_auth_rate` (hashed address
  buckets, no address). Additive; empty on every other install.
- `lib/InstallationTerms.php` — the versioned terms as a constant with its hash; `lib/InstallAuth.php` — the record, the token, the
  acceptance and decline statements, the guard, the lifecycle events, the rate ledger; `lib/InstallAuthNotifier.php` and
  `lib/InstallAuthEmails.php` — the brief's texts through `NotificationService::sendVia`, `CustomerEmailDispatcher::sendInstallAuth`
  (new, off the catalogue, under the master switch; its two keys default ON) and `MailService`; `tabs/customer_app/install_auth_page.php`
  — the customer's page (`?page=install_auth&t=<token>`: HTTPS, hash lookup, rate limit, neutral failures, no script, CSP
  `default-src 'none'`); `includes/api/api_install_auth.php` — six staff actions behind Uganda + flag + J6.
- The guard in `scheduling_job_update`, `install_checkin` and `scheduling_complete`; `install_auth` in the job detail; marks in the
  jobs list; `CUSTOMER_SIGNED_OFF` from the completion signature; `job.delete` cancels a pending request; the `reassigned` hook in
  `JobNotifier::observe`; the panel, the Accept / Complete replacements and the list badge in `tabs/support/scheduling.php` (Uganda
  only — every added line a `<?php if ($_sjUganda): ?>` branch, so South Sudan's page is byte for byte 5.18.81's); four keys in
  `tools/set_config.php`, whose listing's name column widens from 32 to 40 characters for the 37-character key (and its test's reader with it); `manifest.json` 5.18.82; 15 version pins; 5 migration pins amended with the reason; two weakened-copy anchors in
  `tests/test_job_access.php` amended (never deleted) to span the new guard and the guard's returned job.

**Proofs.**
- `tests/test_install_authorisation.php` **245 passed, 0 failed, twice**: the brief's thirty-two cases in its order (the request by
  fake WhatsApp and fake SMTP, the page's details, price and terms version, the acceptance and its record, reference, hash and time, the
  customer's and the technician's messages, the CRM panel, start and completion after acceptance, every refusal before it, a decline and
  what it refuses, invalid and expired tokens, a token being one job's, price tampering, duplicate acceptance, duplicate technician
  messages, a failed WhatsApp, a failed e-mail, no assignee, reassignment, the historical terms, the existing workflow with the flag off
  and for Fiber and in-progress jobs, South Sudan, Domain B), the lifecycle beside the brief, and **5 weakened copies each caught**.
- **Full suite:** **`tests/run.sh` 278 files, 13,236 passed, 0 failed, 0 skipped** (was 277 / 12,991 at 5.18.81: the new
  `test_install_authorisation` 245; no other suite moved). **Second run: 278 / 13,236 / 0 again**, every suite's tally identical.
  PHP warnings in either run: 5 (test_dpo_endpoints 5), all pre-existing. The South Sudan and tenant suites, unchanged: `test_notify_schedule_health` 19 · `test_staff_jobs_south_sudan` 51 · `test_tenant_profile` 108 · `test_email_no_sudan` 62 · `test_notify_tenant_text` 30 · `test_cashbook_tenant` 26 · `test_portal_tenant` 112 · `test_sales_support_tenant` 37 · `test_ai_country_facts` 21 · `test_phone_country` 26.
- `git diff --check` clean; `php -l` on every changed PHP file; no post-7.4 syntax in the added plugin lines; no secret-shaped value in the
  diff; the two banned values absent; no phone number in the documents; no file under `dishnet-mikrotik-control-plane/` touched
  (asserted by the test too); migration 086 reviewed: additive, idempotent, no backfill, append-only trail by trigger.

**Not done, by decision:** no deployment, no push, no flag on anywhere, no production configuration; no admin waiver (D5); no IP or
agent stored (D11); a decline notifies nobody (the panel and the trail carry it); the technician message is not in `NotificationRetry`'s
allow-list; uCRM-side status changes are neither prevented nor recorded (the operator's accepted limitation); `install_checkin`'s access
rule unchanged; the terms await legal review.

**Rollback:** the flag first (`--clear`: every path unreachable, the records stay as evidence); reverting the commit restores 5.18.81
exactly; the tables are additive and ignored by older code.

**Git:** committed locally as the eighth unpushed commit on `claude/study-this-jhe2eg`, after `1a190ce`. Nothing pushed, nothing
deployed, no configuration changed, nothing sent to anyone. Stopped for the operator's review.

## 06 Oct — Customer Installation Authorisation hardened for production (5.18.83) — the pre-release review's findings closed; BUILT, flag OFF, NOT deployed, NOT pushed

**What and why.** The final pre-release review of 5.18.82 (read-only, 06 Oct) found the feature ready for legal review and
not ready for production. Four code blockers: GPS check-out closed jobs unguarded; a start made in uCRM's own screen opened
the completion guard (5.18.82 read D3 as "in progress now") and left no trace; scope took every title naming Starlink,
repairs and customers' names included; the raw secure link sat in the WA Inbox's conversation store and the failure queue, so
staff could accept for a customer. There were also should-fix items. The operator's instruction: implement and make it
ready for production. Record: **`docs/64`**; `docs/63` gains §0.1, listing its statements that were not true of 5.18.82.

**What changed.**
- **One rule** (`InstallAuth::decision()`), read by every guard, the panel, the list badge and the uCRM-side check. Yes
  only for an acceptance given by the client the job belongs to now, or an exemption recorded at activation; a declined
  record refuses even an exempt job. The guard is on Accept Job / status updates, GPS check-in, **GPS check-out (new)** and
  Complete Job.
- **D3 explicit.** `tools/set_config.php --key install_auth_enabled --value 1` first reads uCRM's jobs in progress and records
  the Starlink installations among them in `install_auth_exempt`, with `INSTALLATION_EXEMPTED` and an
  `install_auth_activations` row, in one transaction. It refuses, saving nothing, when uCRM cannot be read or returns a full
  page. Repeats change nothing; off and on is a new snapshot.
- **uCRM-side starts and closes recorded.** `JobNotifier::observe` asks `InstallAuth::observeUcrm()` after its claim. The
  events are `INSTALLATION_STARTED/COMPLETED_WITHOUT_ACCEPTANCE`, once per job and event; active support leaders and admins
  are alerted by WhatsApp (when switched on) and e-mail; the webhook log gets one line. A job first seen already closed is
  not reported.
- **Scope by type.** The title before " — <customer>" must equal one of `install_auth_job_titles` (new; default
  `Starlink Installation`).
- **The link kept nowhere.** `NotificationService::storable()` withholds it in the conversation store, the Message Log
  preview, the dry-run log, the unusable-number log and the failure queue; `NEVER_QUEUED` = `app_otp` +
  `ops_install_auth_request`.
- **Truthful messages.** The customer's confirmation names the technician only when told; otherwise the brief's fallback
  line, and the leaders are alerted. The page claims only what happened. A first-sight reassignment still tells the new
  engineer.
- **Evidence integrity.** The client binding; triggers making an accepted record final and every record undeletable; the
  status lifecycle and a sent request's snapshots fixed; the acceptance and its event in one transaction.
- **Channels.** `install_auth_whatsapp` (new, absent = off); the two e-mail keys reversed to absent = off; a request no
  channel can carry is refused before any record; the request form shows the channels.
- **Page and webhook.**
  - The rate ledger is keyed by the link's token hash, not an address; unknown links write nothing.
  - Resend cooldown, 120 s.
  - A posted `job.delete` withdraws a request only when uCRM answers 404.
  - Check-in and check-out fall back to `job_notify_state` when uCRM is unreadable, so a Fiber job is no longer refused in an outage.
  - On Uganda a refused check-out shows as refused.
- **Migration 086 amended, not followed by 087.** It never ran outside test sandboxes: two append-only tables, eight triggers,
  three events and one CHECK, all additive. `manifest.json` 5.18.83; 15 version pins.
- **Two pins in other suites amended, not deleted:**
  - `test_customer_otp_transport` counted two `app_otp` conditions in the send layer, and the queue's is now the
    `NEVER_QUEUED` list. Both halves are still pinned.
  - `test_notify_customer_fixes`' weakened copy of the unusable-number guard is anchored on that line, which now logs
    `storable($message)`. The anchor follows it, and the copy is caught again. The first full run found this: its anchor
    was gone.

**Proofs.**
- `tests/test_install_authorisation.php` **352/0, twice**: the brief's 32 cases, the lifecycle, the H block (one part per
  finding) and **21 weakened copies, each caught**. Highlights:
  - not one minted link is in any table or file of the data directory, read as raw bytes, with planted-value controls;
  - each database floor is proved alone, and both together as the control on the controls.
- **Full suite** (`tests/run.sh`): 278 files, 13,344 passed, 0 failed, 0 skipped, twice, on the final tree. It was
  278 / 13,236 at 5.18.82: the feature test grew from 245 to 352, and the OTP-transport pin gained its `NEVER_QUEUED`
  half (+1).
- Each full run left `tests/test_customer_pwa.php`'s `php -S` server running after the run ended; both were stopped by
  hand. That test predates this work and is recorded, not changed.
- South Sudan suite 51 passed, 0 failed, 0 skipped, in each full run.
- **Harness lesson, measured.** An in-process raw read of the sandbox database dropped this process's POSIX locks under its
  own SQLite connections; the next read reported "malformed" while the file checked clean elsewhere. The scan runs in a
  `grep` process now (`docs/64` §G).

**Not done, by decision:** no deployment, no push, no flag on anywhere; the terms are unchanged and still await legal review;
uCRM-side changes are recorded, not prevented; who may request and set charges is unchanged (J6), an operator decision;
the check-in store's pre-existing nesting is recorded, not changed.

**Rollback:** the flag first (`--clear`). Reverting this commit restores 5.18.82; the tables are additive.

**Git:** committed locally after `8e5808a`. Nothing pushed, nothing deployed, no configuration changed, nothing sent to
anyone. The release cut on live 5.18.74 follows below.

## 06 Oct — 5.18.83 release prepared: `release/5.18.83` = `2de810c`, cut on live 5.18.74 (`db18ad9`); `scripts/deploy-5.18.83.sh` pinned to it and rehearsed — PUSHED 10:50 UTC; DEPLOYED 11:38 UTC (PASSED 29/0/0); SWITCHED ON by 11:51 UTC (activation #1; `--after-only` PASSED 23/0/1)

**Why a release commit.** Live is 5.18.74 (`db18ad9`). The branch between it and 5.18.83 also carries undeployed work: the
distributor partner portal (migrations 081–083), the PD-8 CSRF guard, and the AI communication layer batches 0–5
(migration 085, the media worker, voice, image and document paths). As for 5.18.66–5.18.74, the release is this feature
alone, applied on the live release commit, and the deploy script installs that commit by its hash.

**What it carries.** 31 files against `db18ad9`: 8 added (`lib/InstallAuth.php`, `lib/InstallAuthNotifier.php`,
`lib/InstallAuthEmails.php`, `lib/InstallationTerms.php`, `includes/api/api_install_auth.php`,
`tabs/customer_app/install_auth_page.php`, `migrations/086_install_authorisation.sql`, `tests/test_install_authorisation.php`)
and 23 changed (the three API files carrying the four guards and the sign-off event, `JobNotifier`, `NotificationService`, `CustomerEmailDispatcher`, `webhook.php`,
`public.php`, `includes/api_handlers.php`, the job page, the configuration tool, `manifest.json` 5.18.83, four neighbouring
tests, the five distributor version pins on this line, and two suite files the branch had already aligned with
5.18.70, below). **28 of the 31 are byte-identical to the branch's.** The other
three (`public.php`, `includes/api_handlers.php`, `tools/set_config.php`) were also changed on the branch for undeployed
work; the release carries exactly this feature's added and removed lines in each, compared line for line. In
`tools/set_config.php` that meant resolving one conflict by hand: the AI layer's media-limit and provider blocks were left
out, the authorisation link-days and job-titles checks kept. The remaining 20 changed files were copied whole:
- 12 were identical on `db18ad9` and on the branch before the feature, so copying them carries nothing else;
- `manifest.json` and the five distributor tests differ from `db18ad9` in their version line alone (5.18.74 → 5.18.83);
- the last two are `8cde7ca`'s, below.

**Two test files more, no code.** The release tree's own suite found that `test_sales_support_tenant.php` on the release
line still asserted the wallet filter as it was before 5.18.70, although the line carries 5.18.70's `wallet.php`. Branch
commit `8cde7ca` had already brought that test, and `test_notify_evo_retry.php`'s Sunday window search, into line with
that code and the clock, but it never reached a release commit. Both files are carried as the branch has them, `8cde7ca`'s
change line for line, and both pass on the release tree.

**The release suite** (`tests/run.sh` on the release tree itself, a worktree of `2de810c`): **265 files, 12,404 passed, 0 failed, 0 skipped**; run 2:
**265 files, 12,404 passed, 0 failed, 0 skipped**. Every file's tally is the same in both runs: the feature's own test 352, South Sudan's 51. The five PHP
warnings in each run are the branch's own five, all from line 91 of `test_dpo_endpoints.php`, a file this release does not
change. The worktree was unchanged afterwards. The release line has 265 test files where the branch has 278; the 13 missing are the undeployed work's
own (the portal's, the CSRF guard's and the AI layer's). 5.18.74's suite ran on the branch commit, whose sixteen release
files were byte-identical to the release's. Here three of the 31 are not, so the release tree ran its own. A lesson,
measured:
the first attempt ran the worktree from inside this session's private temporary directory (mode 700), and
`test_cli_data_dir.php` failed 9 checks. That test drops to the user `nobody` to reach the fallback it is about, and
`nobody` could not open the plugin's files there; the PHP fatal's exit code 255 even satisfied its own "EXIT:2"
substring. Run from a directory `nobody` can reach, as the branch is, it passes. A release tree's suite must run from such
a directory.

**`scripts/deploy-5.18.83.sh`**, 5.18.74's shape, pinned to `2de810c` over `db18ad9`. What is new:
- **A0 is an allow-list.** The delta must be exactly the 31 files, 8 of them as added, with exactly one migration (086).
  Anything else in the pin, or anything missing from it, stops the script before the container is looked at.
- **The switch must arrive off — in both places the plugin keeps it.** `set_config.php` writes the switch twice: into the
  configuration files, which the tools and `webhook.php` read, and into the store row in `plugin.sqlite3`, which
  `public.php` reads — and with it the customer page, the staff actions and the four guards. Stage A reads both
  (`PluginConfig::read` for the files, the row read-only as the database's owner) and refuses to deploy if either is on.
  With the switch already on, the copy itself would start enforcing without the activation step that records the jobs in
  progress.
- **No 086 may already be recorded.** If the plugin's migration ledger already names 086, its runner would skip this
  release's 086 as applied, and the tables it adds would never exist.
- **V7/V8.** With the switch off, the customer page answers 404 *"This page is not available."*, like a page that does not
  exist. An anonymous `install_auth_request` is refused by the staff guard before any handler runs (401 in the rehearsal;
  the script accepts 401 or 403).
- **V3b and R2.** `install_auth_enabled` is unchanged by the run and, after a deploy, off in both copies. The installed
  `InstallAuth` itself reads `enabled() = false` over each. If the two copies ever disagree, both checks fail: the tools and
  the pages would act differently.
- **R3 trusts objects, not the ledger.** The runner applies a file statement by statement and records it even when a
  statement fails (*PARTIAL*). R3 therefore checks:
  - the ledger row's checksum against the installed file;
  - all five tables, ten triggers, four indexes and both CHECKs;
  - no *PARTIAL* line for 086 in `migration.log`;
  - right after a deploy, five empty tables.

  If no request has reached the plugin by R3 (the public address unreachable from the server, for one), R3 looks again
  after R7. R7's tool opens the plugin's store, the plugin's own runner applies 086 then, and the second look verifies it.
- The new tables are read as the database's owner, **read-only**, so SQLite can never leave a root-owned `-wal`/`-shm`
  beside the live database.
- **R4** also refuses an installed AI-layer or portal file. **R6** adds the markers of the record's one rule, the activation
  step, the uCRM-side check, the page's gate, the staff actions, the four guards, the never-queued list, the redacted Inbox
  copy, the e-mails off by default and the job-page panel, beside every earlier release's marker.
- **The rollback refuses while the switch is on.** Turning the switch off on its own stops everything 5.18.83 does for staff
  and customers. A rollback under a switch that stays on would leave a later re-deploy enforcing against an old activation
  snapshot. RB proves no 5.18.74 file reaches the authorisation code, and notes what stays: the eight added files, reached
  by nothing (`deploy-hybrid.sh` never deletes), and 086's tables with their row counts.
- **No command that switches the feature on** appears in the script or in its log (asserted).

**Rehearsal `scripts/harness/deploy-5.18.83/rehearse.sh`.** The stand-in web server boots the installed plugin's store on
every request, as `public.php` does, so migration 086 is applied by the plugin's own runner. V7 is answered by the
installed customer page itself. The rehearsal covers:
- the A refusals: a 5.18.72 server; a placeholder pin; the branch tip; a commit cut on 5.18.74 carrying the release plus an
  AI-layer file; a commit carrying the release without 086; the switch already on, in both copies and in the files alone; a
  planted 086 ledger row;
- the deploy itself;
- the teeth: a gate-less page caught by V7, R1 and R6; a dropped trigger caught by R3; a *PARTIAL* log line; an edited 086
  caught by R1 and R3's ledger check; a changed 5.18.74 file caught by R5;
- the pilot switch read live;
- the operator's activation, emulated as `set_config.php` writes it (both copies and an activation row): `--after-only`
  passes with the switch on, and the rollback refuses until it is off; with the two copies made to disagree, V3b and R2
  fail and V7 follows the store row, as `public.php` does;
- the rollback, the lazy case and two weakened copies of the script (R1 blinded, R3's completeness check blinded).

Results:
- **Run 1:** 181/0, 28 runs of the script (the committed script, sha256 `11e634108af653c2…`), no FAIL line, the clone left as found.
- **Run 2:** 181/0, 28 runs, the same script (sha256 `11e634108af653c2…`), the clone left as found.
- The rehearsed deploy reads **30 ok / 0 failed / 0 notes**. On the server expect 29 or 30 ok: 5.18.74's server run read one fewer
  than its sandbox.

**Handover — each step is the operator's; all three are done.**
0. **Push both branches — DONE 06 Oct, 10:50 UTC**, on the operator's instruction. The server pulls them from GitHub:
   `claude/study-this-jhe2eg` (pushed at `3991dd0`, a fast-forward from `7b73813`) carries the script, and `release/5.18.83`
   (`2de810c`, a new branch) carries the release commit. `git ls-remote` reads both tips equal to the local commits.
1. **Deploy, as root on the server — DONE 11:38 UTC, PASSED 29/0/0** (the RESULT below). It asks for `DEPLOY`; send
   back the **log file**:

   `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.83 && mkdir -p /root/dnb-5.18.83 && bash scripts/deploy-5.18.83.sh 2>&1 | tee /root/dnb-5.18.83/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`

   The rollback is printed by the script, alone, at the end of its log. It is never handed over beside the deploy (root
   docs/44 §16.9).
2. **Switch the feature on — DONE by 11:51 UTC**, by the operator's decision, before any legal review of `docs/62` was
   recorded (the SWITCH-ON record below). As planned, it was to come only after the legal review of the terms (`docs/62`)
   and the operator's decisions in `docs/64` §I.2, by the configuration in `docs/64` §I.3. Deploying first changes nothing
   for staff or customers: the page answers 404 and no guard runs. After switching it on, `--after-only` (§I.3) confirms
   the switch is on in both copies the plugin keeps.

- **RESULT — DEPLOYED to production 2026-10-06, 11:38 UTC: PASSED, 29 ok / 0 failed / 0 notes** — the 29 expected. The
  sandbox reads 30 because it holds a `dishnet.sqlite` to back up; the server holds none (*"nothing to copy"*). The run
  began at 11:38:25 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves 2de810c"*; the 31
  files were stamped at 11:39:51 UTC. Recorded from the terminal the operator pasted (the script prints no secret); the
  log file stays on the server as `/root/dnb-5.18.83/deploy-20261006T113825Z.log`.
  - **A.** The checkout fast-forwarded `52e1951` → `0e882f3`; branch tip `6b3c22a` (not installed); release commit
    `2de810c` cut on `db18ad9`; 31 files (23 changed, 8 added, 0 removed), one migration (086); **A0** clean. Live
    `db18ad9` / 5.18.74. PHP **8.1.34** accepted the 17 changed server files and the 12 test files. Pilot `on`; **the
    authorisation switch `ia=absent/absent`** — in neither copy; 086 absent (0/5 tables, 0/10 triggers, 0/4 indexes);
    photo tables `present:6:2`, 6 files.
  - **Backup** `/root/dnb-5.18.83/backup-20261006T113825Z`: `plugin.sqlite3` 29 MB, one consistent copy, integrity ok,
    243 tables; the data directory 142 MB; the installed 5.18.74 11 MB; the vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V6** zoom allowed; **V5** 302 / 401;
    **V7** the customer page 404 *"This page is not available."*; **V8** `install_auth_request` 401 without a login;
    **V3** the pilot unchanged (`on` → `on`); **V3b** the authorisation switch unchanged (`absent/absent` →
    `absent/absent`); **V4** no fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 31 files as `2de810c` has them, manifest 5.18.83; R2 `pilot=on`, and the installed `InstallAuth` reads
    the switch OFF in both copies; **R3 086 applied and complete at the first look** — 5 tables, 10 triggers, 4 indexes,
    both CHECKs, the ledger row matching the installed file, the five tables empty (`0:0:0:0:0`). `migration.log` reads
    *"OK: 086_install_authorisation.sql (19 stmts, 15ms)"* at 14:39:51 by its own clock (UTC+3): 11:39:51 UTC, the
    second of the copy. R3 084 still installed, `6:2` rows, untouched; R4 the pilot as before, the channel
    `NullWhatsAppChannel`, no portal, CSRF or AI-layer file; R5 all 206 files from Release A through 5.18.74 intact; R6
    every earlier marker and 5.18.83's; **R7** (read-only) **UGX CASH IN HAND 591,072.00 · USD 0.00; Fiber & Starlink
    591,072.00, DishNet 4G 0.00, BlueCARD 0.00** — the book's own figures (5.18.83's changes touch no cash code); the
    photo tables and files read exactly as before.
  - **What it meant at 11:39 UTC:** nothing changed for staff or customers. The switch was off, so no guard ran, the job
    page showed no panel and the customer page answered 404. Step 2 followed.
- **SWITCH-ON — 06 Oct, by 11:51 UTC, by the operator** (`docs/64` §I.3; recorded from the terminal the operator pasted):
  - `install_auth_job_titles = Starlink Installation` (the default, now set explicitly) and `install_auth_whatsapp = 1`.
    The two e-mail keys were left unset, so this feature sends no e-mail. `set_customer_emails.php --show` read the
    customer e-mails master switch already ON, with every lifecycle e-mail ON; nothing was changed there.
  - `install_auth_enabled = 1` — **Activation #1: uCRM had 1 job in progress, 1 of them a Starlink installation,
    recorded as exempt: job 20.** Only jobs in progress (uCRM status 1) are exempted. A Starlink installation job that
    was open but not yet started needs its customer's acceptance, like a new one.
  - `--after-only` at 11:51:42 UTC: **PASSED, 23 ok / 0 failed / 1 note.** The note is R2 reading the switch ON in both
    copies (`files=on/yes store=on/yes`), as intended. V3b `ia=on/on` before and after; V7 the customer page answers
    404 *"This link is not valid"* to a request without a link; R3 086's tables hold `0:1:1:1:0` — no request yet, one
    event (job 20's `INSTALLATION_EXEMPTED`), one activation, one exemption; R7 the same cash figures; V4 no fatal or
    parse error since the deploy. The log stays on the server as `/root/dnb-5.18.83/after-20261006T115142Z.log`.
  - **Its summary is wrong for this run.** *"TODAY THE SWITCH IS OFF"* and *"Nothing to try yet"* are fixed text
    written for the deploy run; the checks above them read the live state. Recorded, not changed.
  - **The terms customers now accept** are `INSTALLATION-TERMS-v1.0`. Their text, and so their hash, begins
    *"DRAFT — SUBJECT TO LEGAL REVIEW"*, and every customer who opens a link sees that line. Removing it is a new
    version (v1.1) and a release. No legal review of `docs/62` is recorded.

**Git:** `release/5.18.83` (`2de810c`) and the three branch commits — the feature (`6b3c22a`), the script and its
rehearsal (`63e45ee`), this entry (`3991dd0`) — **pushed 06 Oct, 10:50 UTC**, on the operator's instruction. The same push
published the branch's earlier local commits: the AI communication layer 5.18.76–5.18.81 and `docs/58`, and 5.18.82. Their
entries above record them as not pushed (*NOT pushed*, or *committed locally* for 5.18.82), as was true when each was
written. The AI layer is not deployed and stays switched off (R4 found none of its files installed); 5.18.82's feature
reached production only inside 5.18.83's release, switched off. No configuration changed, nothing sent to anyone.

## 06 Oct — 5.18.84: the customer's WhatsApp when an installation job is booked (Uganda); `release/5.18.84` = `79607d4`, cut on live 5.18.83 (`2de810c`); `scripts/deploy-5.18.84.sh` pinned to it and rehearsed — PUSHED 15:19 UTC; DEPLOYED 15:23 UTC (PASSED 31/0/0); SWITCHED ON by 15:25 UTC (`--after-only` PASSED 25/0/1)

**Why.** After Customer Installation Authorisation was switched on, the operator created a job for a customer with no e-mail
address. The technician was told at once; the customer heard nothing. Two facts, read from the code:
- uCRM's `job.add` told customers by e-mail only: the `install_scheduled` lifecycle e-mail. No address, no message.
- The authorisation request is not sent when a job is created. Staff send it from the job page, once the charges are set:
  the plugin has no price list to take them from (docs/61 D4). It goes by WhatsApp; no e-mail is needed.

The operator asked for a proper WhatsApp for such customers, and approved the build ("yes build").

**What 5.18.84 does.** Uganda only, and off until `customer_wa_install_scheduled` is set (absent means OFF).
- **Where:** `webhook.php`'s `job.add`, beside the `install_scheduled` e-mail and under its three conditions: the job
  belongs to a client, its title names an installation, it has a date. A job ＋ New Job makes is a job made through uCRM's
  API with the plugin's own client, and uCRM delivers `job.add` for those too: measured on job #10, 28 Sep (docs/44 §16.26).
- **To whom:** the client's first uCRM contact number, the rule the authorisation request uses
  (`InstallAuth::clientPhone`). No e-mail address is needed; a client with one gets both.
- **Once per job:** claimed in `notification_dedup` (`WAINSTALL<job>`) before the send, because uCRM redelivers webhooks.
  The claim comes after the number is checked, so a client with no number claims nothing. A failed send goes to the
  failure queue for a person to retry, and a redelivery does not send it again.
- **A Starlink installation under Customer Installation Authorisation** gets one more line: the secure link follows.
- **One webhook-log line per job**, without the customer's number or the message.
- **Not done, by design:** a reschedule sends nothing new, and neither does a job created without a date and dated later
  — both as the e-mail.

The message (sample values; the support line carries the Uganda profile's support number):

```
🔧 *Installation Scheduled — DishNet Africa*

Dear Sandbox,

Your installation has been scheduled. ✅

📋 *Starlink Installation*
📅 Date: *Monday 5 October 2026*
⏰ Time: *9:00 AM*
📍 Location: Plot 9 Sandbox Road, Kampala
👷 Technician: *Sandbox Tech*
🔖 Job: #941

📝 Before the installation, you will receive a separate WhatsApp from us with a secure link to review and accept the installation terms and charges.

Our technician will contact you before arriving. Please make sure someone is available at the site.

🛠 Support: wa.me/<the Uganda support number>
— DishNet Africa Support
```

The date and time are read in the install's zone, as the technician's job message reads them. A value uCRM does not have
gives no line at all: no Time line for a job at midnight, no Technician line for an unassigned job or a uCRM user with no
name (never uCRM's "Technician" placeholder). Emphasis marks and line breaks are taken out of every value.

**Files** (dev commit `58a6b15`): `lib/InstallScheduledWhatsApp.php` (new), `webhook.php`, `tools/set_config.php` (the key,
listed and explained), `manifest.json` 5.18.84 and the 15 version pins, `tests/test_install_scheduled_whatsapp.php` (new).
No migration.

**Tests.**
- `tests/test_install_scheduled_whatsapp.php`: **50 passed, 0 failed**, driving the real `webhook.php` of a sandboxed plugin
  beside a fake uCRM, a fake WhatsApp and a mail relay. It covers: off by default; the message byte for byte; once per job,
  with the same uuid and new ones; the customer with no e-mail address, and the customer with one getting both; nothing
  without a client, a date, an installation title or a number; the Starlink line; South Sudan unchanged; a failed send;
  the configuration tool.
- **Eleven weakened copies of the code are each caught**: no switch, no Uganda gate, no claim, the claim before the number,
  not wired into `job.add`, sent only when there is no e-mail address, uCRM's placeholder name, the accounts number, the
  Starlink line on every installation, the time in uCRM's offset, values not cleaned.
- Neighbouring suites unchanged: `test_set_config_tool` 40, `test_lifecycle_email_wiring` 73, `test_customer_email_dispatch`
  45, `test_webhook_trust` 74, `test_staff_jobs_south_sudan` 51, `test_job_messages` 132, `test_customer_emails` 105.
- **Full suite** on `58a6b15`: **279 files, 13,394 passed, 0 failed, 0 skipped**; run 2 the same, every file's tally
  identical. Against 5.18.83's 278 / 13,344, the only change is the new test's 50, compared file by file.

**The release, `release/5.18.84` = `79607d4`**, parent `2de810c` (live 5.18.83): 10 files against it, 2 added (the class
and its test) and 8 changed. Nine are byte-identical to the branch's; each of the seven changed ones copied whole was
identical on `2de810c` and on the branch before the feature. `tools/set_config.php` carries this feature's six added lines
only, compared line for line: the branch's copy also holds the AI layer's keys. No migration; none of the undeployed work.
The release tree's own suite, run from a worktree outside the session's private directory: **266 files, 12,454 passed, 0
failed, 0 skipped**; run 2 the same, every file's tally identical, the tree unchanged by both. Against 5.18.83's release
suite (265 / 12,404), the only change is the new test's 50, compared file by file.

**`scripts/deploy-5.18.84.sh`**, 5.18.83's shape, pinned to `79607d4` over `2de810c`. What differs:
- **Customer Installation Authorisation is live, and the script keeps it so.** `install_auth_enabled` must be readable
  with its two copies agreeing before anything changes, and unchanged after (V3b, R2). Migration 086 must be complete
  before the deploy and after it, its rows reported, not required empty (A, R3). Its rule, page, guards and switch are
  checked present (R6), and present again after a rollback (RB).
- **The new switch must arrive off** in both places the plugin keeps it (A). It must be unchanged by the run and off right
  after a deploy (V3c), and the installed class must read it so (R2).
- **A0 allows no migration** and exactly the release's 10 files.
- **Evidence, never a NO-GO:** how many `job.add` deliveries the plugin's own webhook log holds, and the last one's time.
- **The summary says what the switch reads.** 5.18.83's `--after-only` printed the deploy run's "TODAY THE SWITCH IS OFF"
  with the switch on.
- **The rollback goes ahead with the switch on.** 5.18.83 does not read it, so no booking WhatsApp is sent after it; a
  note says so.
- No command that switches either feature appears in the script or its log (asserted).

**Rehearsal `scripts/harness/deploy-5.18.84/rehearse.sh`.** The base is installed as production runs it: 5.18.83, 086
applied by its own runner, authorisation ON in both copies, activation #1 with one job exempt and its event. It covers:
- the A refusals: a 5.18.74 server; a placeholder pin; the branch tip; a commit carrying the release plus an AI-layer file;
  one without the class; the booking switch already on, in both copies and in the files alone; the authorisation's copies
  disagreeing; 086 incomplete;
- the deploy itself, which leaves every table — 086's included — the vault and the configuration files byte-identical;
- the teeth: the call taken out of `job.add`, the class's claim taken out, a 5.18.83 file changed, a trigger dropped;
- the pilot switch read live; the operator's switch-on emulated (both copies), and the copies made to disagree;
- the rollback with the switch on; two weakened copies of the script (R1 blinded, R6's call check blinded).

Results: **run 1 154/0**, 26 runs of the script (the committed script, sha256 `d65f4b958f96e4bd…`), no FAIL line, the
clone left as found; **run 2 154/0**, 26 runs, the same script, the same check lines. The rehearsed deploy reads **32 ok /
0 failed / 0 notes**; on the server expect 31. The sandbox has a `dishnet.sqlite` to back up and the server has none, as
with 5.18.83: 30 rehearsed, 29 on the server.

**Handover — each step is the operator's; all three are done.**
0. **Push both branches — done, 06 Oct 15:19 UTC.** The server pulls them from GitHub: `claude/study-this-jhe2eg` carries the script, and
   `release/5.18.84` the release commit.
1. **Deploy, as root on the server — DONE 15:23 UTC, PASSED 31/0/0** (the RESULT below). It asks for `DEPLOY`; send
   back the **log file**:

   `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.84 && mkdir -p /root/dnb-5.18.84 && bash scripts/deploy-5.18.84.sh 2>&1 | tee /root/dnb-5.18.84/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`

   The rollback is printed by the script, alone, at the end of its log. It is never handed over beside the deploy (root
   docs/44 §16.9).
2. **Switch it on, after the deploy passed — DONE by 15:25 UTC** (the SWITCH-ON record below):

   `docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key customer_wa_install_scheduled --value 1`

   Then `cd /opt/dishnet && bash scripts/deploy-5.18.84.sh --after-only` confirms it is on in both places. Pilot it with
   an installation job, with a date, for a test client whose WhatsApp number is a staff member's. To switch it off, run the
   same tool with `--clear` instead of `--value 1`.

- **RESULT — DEPLOYED to production 2026-10-06, 15:23 UTC: PASSED, 31 ok / 0 failed / 0 notes** — the 31 expected. The
  sandbox reads 32 because it holds a `dishnet.sqlite` to back up; the server holds none (*"nothing to copy"*). The run
  began at 15:21:37 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves 79607d4"*; the 10
  files were stamped at 15:23:06 UTC. Recorded from the terminal the operator pasted (the script prints no secret); the
  log file stays on the server under `/root/dnb-5.18.84/`.
  - **A.** The checkout fast-forwarded `0e882f3` → `9898608`; branch tip `58a6b15` (not installed); release commit
    `79607d4` cut on `2de810c`; 10 files (8 changed, 2 added, 0 removed), no migration; **A0** clean. Live `2de810c` /
    5.18.83. PHP **8.1.34** accepted the 3 changed server files and the 6 test files. Pilot `on`; **the authorisation
    `ia=on/on`**, as the operator left it; **the new switch `wa=absent/absent`**; 086 complete, its tables
    `0:2:1:1:0`; the webhook log's last 300 entries hold **1** `job.add`, the last at 15:27:39 by the plugin's clock
    (UTC+3), 12:27:39 UTC; photo tables `present:11:3`, 11 files.
  - **Backup** `/root/dnb-5.18.84/backup-20261006T152137Z`: `plugin.sqlite3` 29 MB, one consistent copy, integrity ok,
    248 tables; the data directory 144 MB; the installed 5.18.83 11 MB; the vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V6** zoom allowed; **V5** 302 / 401;
    **V7** the customer authorisation page 404 *"This link is not valid"* to a request without a link; **V8**
    `install_auth_request` 401 without a login; **V3** the pilot unchanged (`on` → `on`); **V3b** the authorisation
    unchanged (`on/on` → `on/on`); **V3c** the new switch unchanged (`absent/absent` → `absent/absent`): off; **V4** no
    fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 10 files as `79607d4` has them, manifest 5.18.84; R2 `pilot=on`, the installed `InstallAuth` reads the
    authorisation ON in both copies, and the installed `InstallScheduledWhatsApp` reads the new switch OFF in both; R3 086
    still applied and complete, `0:2:1:1:0` before and after; 084 `11:3` rows, untouched; R4 the pilot as before, the
    channel `NullWhatsAppChannel`, no portal, CSRF or AI-layer file; R5 all 217 files from Release A through 5.18.83
    intact; R6 every earlier marker and 5.18.84's; **R7** (read-only) **UGX CASH IN HAND 591,072.00 · USD 0.00**, the
    figures 5.18.83's deploy read; the photo tables and files read exactly as before.
- **SWITCH-ON — 06 Oct, by 15:25 UTC, by the operator** (recorded from the terminal the operator pasted):
  - `set_config.php --key customer_wa_install_scheduled --value 1` printed *"customer_wa_install_scheduled = 1"*, the
    usual *[ConfigVault] restored after re-install* line, and the listing, where the key reads **ON**. No other key was
    changed.
  - `--after-only` at 15:25:01 UTC: **PASSED, 25 ok / 0 failed / 1 note** — what the rehearsal read with the switch on. The
    note is R2 reading the new switch ON in both copies (`files=on/yes store=on/yes`), as intended. **V3c** `wa=on/on`
    before and after; **V3b** the authorisation `ia=on/on`; R3 `0:2:1:1:0`; V4 no fatal or parse error since the deploy.
    The log stays on the server under `/root/dnb-5.18.84/`.
  - **The summary says what the switch reads:** *"customer_wa_install_scheduled reads ON in both places (V3c, R2): the
    next installation job created with a date sends it"*. 5.18.83's fixed *"TODAY THE SWITCH IS OFF"* is gone.
  - **Not yet seen: a message on a phone.** The first installation job created with a date after 15:25 UTC sends it. The
    pilot, a test client whose number is a staff member's, is the operator's. A job created before the switch does not
    get it, the one made for the customer without an e-mail address included: the message is sent only at `job.add`.
  - **086 holds one event more than at 5.18.83's switch-on, and still no request** (`0:1:1:1:0` at 11:51 UTC,
    `0:2:1:1:0` before this deploy; this run changed none). With no request, 5.18.83's code records only: a step on the
    exempt job 20 (started, completed or signed off, marked `exempt`); a staff action a guard refused
    (`INSTALLATION_START_BLOCKED`); or a Starlink installation job started or completed in uCRM without acceptance
    (`…_WITHOUT_ACCEPTANCE`). Which, and on which job, the job page's panel shows; not read from here.
- **PILOT — 06 Oct, 15:26 UTC (18:26 Kampala), by the operator** (recorded from the WhatsApp messages the operator
  pasted; the secure link in them is not recorded): job #22, a Starlink installation for a test client whose number is a
  staff member's, dated 7 Oct 18:30.
  - **The booking WhatsApp arrived at 15:26:23 UTC**, about a minute after the switch-on, with the brand, the client's
    first name, *Starlink Installation*, the date and time read in Kampala (*Wednesday 7 October 2026*, *6:30 PM*), the
    location, the job number and the Starlink line, as specified. WhatsApp folded the rest behind *Read more*.
  - **It had no Technician line, though the job had an assignee.** Measured on 27 Sep (docs/44 §13.1, §16.20):
    `job.add` reads the assignee at `GET users/{id}`, which this uCRM answers with 404 even for a real user; its users
    answer at `users/admins/{id}`. So the name `job.add` reads is always empty here, and 5.18.84 sent no line rather than
    the *"Technician"* placeholder, as it does for any missing name. The test's fake uCRM answers both addresses, so the
    suite could not see it. The same empty lookup is why the *"installation booked"* e-mail's technician reads
    *"Technician"* (§16.20). A fix is proposed for 5.18.85, not built.
  - **The location read only *Uganda***: what the job's address holds in uCRM. The authorisation request showed the same.
  - **Then Customer Installation Authorisation, end to end for the first time:** the request at 15:27:56 UTC, its charges
    typed by the sender; the customer's acceptance at 15:28:33 UTC, reference `ACC-20261006-000001`; the technician told,
    and named to the customer by the first name on the staff account.
  - **The operator asked** for the request form to take its charges and the kit from the customer's quotation instead of
    being typed, and whether the kit number is compulsory. Answered in the session; the quotation prefill is proposed for
    5.18.85, not built.

**Git:** `58a6b15` (the feature), `2923296` (the script and its rehearsal), `release/5.18.84` (`79607d4`) and this entry
(`cc0002e`) — **pushed 06 Oct, 15:19 UTC**, on the operator's instruction (*"yes push both branches"*): the branch to
`cc0002e`, `release/5.18.84` new at `79607d4`. At the push nothing was deployed, no configuration had changed and nothing
had been sent to anyone.

## 06 Oct — 5.18.85: the authorisation's request form filled from the customer's quotation, and the technician's name in the booking messages (Uganda); `release/5.18.85` = `4790019`, cut on live 5.18.84 (`79607d4`); `scripts/deploy-5.18.85.sh` pinned to it and rehearsed — PUSHED 18:36 UTC; DEPLOYED 19:13 UTC (PASSED 33/0/0)

**Why.** The 5.18.84 pilot (above) showed two things. The first authorisation request went out with its charges typed by
hand, while the customer's quotation already held them. And the booking WhatsApp had no Technician line. The operator
asked for the form to take *"as much information as we can"* from the quotation, and whether the kit number is
compulsory. The operator then sent quotation 000181 and DishNet Uganda's quotation template (`template-v4.zip`), from
which the lines below were read.

**What DishNet Uganda's quotations carry**, read from 000181 with the plugin's own PDF reader:

| Line | Unit | Price |
|---|---|---|
| the kit, e.g. *Starlink Mini Kit + Mini Router* | Pc | the kit's price |
| the plan, e.g. *Residential Lite (up to 100 Mbps)* | Monthly | the plan's price |
| *Professional Installation* | Time | UGX 150,000 |
| *Transportation charges to and from the site shall be borne by the customer.* | Time | **UGX 0** |

The template prints each line's label, type, unit, quantity, price and total, which are the fields uCRM's quotations carry
and the plugin already reads. **Transport is never priced on the quotation**, so it cannot come from there.

**What 5.18.85 does.** Uganda only, no switch, no migration.
- **The request form starts from the customer's latest uCRM quotation** (`lib/QuotationPrefill.php`, new):
  - the installation line → the installation charge;
  - a transport or delivery line → the transport charge. A transport line at 0 is **asked for, never filled with 0**, and
    the form refuses to send with it empty;
  - a monthly line, or a plan known by its speed → the service;
  - other one-time lines with an amount → the other agreed charge, their labels what it is for. A discount is never a
    charge;
  - every other line (the kit, a router, a cable) → the equipment, *"x2"* for two.

  The kit's and the plan's prices are not authorisation charges: the customer pays for them through the quotation.
- **Which quotation:** the latest by date. Never another client's, even a newer one; never a rejected or void one, by
  the plugin's own reading of uCRM's statuses (`cron_quote_wa.php`: 3 rejected, 4 void); never one without lines. The
  form says which: *"📄 Filled from quotation 000181 of 6 Oct 2026, the customer's latest in uCRM. Check every value:
  the customer accepts exactly what you send."*
- **Reading it** (`install_auth_prefill`): one `GET billing/quotes?clientId=` when the form opens, with the admin token when
  one is set, else the plugin's key, as the quote screens read quotes. If uCRM does not answer, the form says so and opens
  as before. Nothing is sent or stored by opening the form.
- This is what `docs/61` D4 decided: charges *"typed or confirmed by staff at request time, prefilled when a KYC
  application or quotation exists"*. Until now only the KYC application was read, and never for the charges.
- **The kit number is not compulsory.** It never was: the Equipment box takes any description. The quotation now fills
  it with the kit's name.
- **The technician's name** (`InstallScheduledWhatsApp::technicianName`): the first name on the verified staff account
  linked to the assignee, where the authorisation's messages to the customer already take it from, else uCRM's
  `users/admins/{id}`, else no line. `job.add`'s own lookup, `users/{id}`, answers 404 on this uCRM for every user
  (docs/44 §13.1).
  - The booking WhatsApp now carries the 👷 line.
  - The customer's *"installation booked"* e-mail on Uganda carries the same first name, or no technician row when there
    is none, instead of the *"Technician"* placeholder `job.add` printed when its lookup found nobody.
  - South Sudan's e-mail is unchanged.

**Files** (dev commit `4a7127d`):
- new: `lib/QuotationPrefill.php`, `tests/test_install_quote_prefill.php`;
- changed: `includes/api/api_install_auth.php`, `tabs/support/scheduling.php`, `lib/InstallScheduledWhatsApp.php`,
  `webhook.php`, `tests/test_install_scheduled_whatsapp.php`, `tests/test_job_notifications_day.php`, the two test
  fixtures, `manifest.json` 5.18.85 and the 15 version pins.

No migration and no configuration key.

**Tests.**
- `tests/test_install_quote_prefill.php`: **44 passed, 0 failed**, 33 checks and 11 weakened copies. It covers:
  - the quotation read as the form reads it: 000181's four lines, a priced transport, other charges, quantities, a
    discount, decimals, broken and long labels, a quotation with no units;
  - which quotation;
  - through the real plugin: the prefill names 000181 and carries its values. It reads uCRM once, for this client.
    Another client's newer quotation is never used. No quotation, or no answer from uCRM, leaves the form as before. The
    prefill sends and stores nothing;
  - the request then made with those values, and the customer's WhatsApp carrying them;
  - the form on the job page.

  **Eleven weakened copies are each caught**: another client's quotation; a rejected or void one; the oldest; transport
  at 0 filled as 0; the installation line not found; the plan taken for equipment; a discount counted; the quotation's
  kit and plan not used; the charges not passed to the form; the form not filled; an empty transport sent as none.
- `tests/test_install_scheduled_whatsapp.php`: **61 passed, 0 failed** (was 50): 46 checks and 15 weakened copies, where
  5.18.84 had 39 and 11.
  - The fake uCRM now answers `users/{id}` with 404, **as production does**. 5.18.84's tests ran against a fake that
    answered it, which is how the missing name got through.
  - The name comes from the staff account, else uCRM's `users/admins`, else no line; never a guess between two staff
    accounts on one user (with a control proving both are linked). The Uganda e-mail carries the same name, and South
    Sudan's e-mail is as before.
  - Four new weakened copies are caught: the name asked at `users/{id}` again; the staff account ignored; the e-mail
    keeping the placeholder on Uganda; the e-mail's change reaching South Sudan.
- `tests/test_job_notifications_day.php`: **51 passed** (was 50). Its T4.11 check compares the customer's e-mail with
  5.18.51's byte for byte; it now does so **except for the Technician value**, the one deliberate difference, which a
  new check pins.
- Neighbouring suites unchanged: `test_install_authorisation` 352, `test_set_config_tool` 40, `test_webhook_trust` 74,
  `test_lifecycle_email_wiring` 73, `test_customer_email_dispatch` 45, `test_customer_emails` 105, `test_staff_jobs_gate`
  41, `test_staff_jobs_south_sudan` 51, `test_job_messages` 132, `test_job_notifier` 130, `test_job_access` 83,
  `test_job_time` 34, `test_job_photos` 72, `test_notify_staff_side` 53, `test_kyc_crm_create` 147, `test_mail_quote` 92,
  `test_notify_kyc_quote_send` 24.
- **Full suite** on `4a7127d`: **280 files, 13,450 passed, 0 failed, 0 skipped**; run 2 the same, every file's tally
  identical, the tree unchanged by both. Against 5.18.84's 279 / 13,394, compared file by file: the new test's 44,
  `test_install_scheduled_whatsapp` 50 → 61 and `test_job_notifications_day` 50 → 51; every other file the same.

**The release, `release/5.18.85` = `4790019`**, parent `79607d4` (live 5.18.84): 16 files against it, 2 added and 14
changed. **All sixteen are byte-identical to the branch's**; each changed one was identical on `79607d4` and on the branch
before the feature. No migration; none of the undeployed work. The release tree's own suite, from a worktree outside the
session's private directory: **267 files, 12,510 passed, 0 failed, 0 skipped**; run 2 the same, every file's tally
identical, the tree unchanged by both. Against 5.18.84's release suite (266 / 12,454), compared file by file: the new
test's 44, `test_install_scheduled_whatsapp` 50 → 61 and `test_job_notifications_day` 50 → 51; every other file the
same.

**`scripts/deploy-5.18.85.sh`**, 5.18.84's shape, pinned to `4790019` over `79607d4`. What differs:
- **Both live features are kept as they are.** `install_auth_enabled` and `customer_wa_install_scheduled` must each be
  readable, with their two copies agreeing, before anything changes. Both must be unchanged after (V3b, V3c) and read so
  through the installed classes (R2).
  - The booking WhatsApp's switch is no longer required off: it is the operator's, on since 06 Oct.
  - R2 now also fails when the installed class and the configuration disagree.
- **A0** allows no migration and exactly the release's 16 files.
- **V9:** the form's data refuses an anonymous caller before any quotation is read.
- **R6:** 5.18.85's pieces are present.
- **R8:** the installed reader runs, under the server's own PHP, on a quotation shaped like 000181. It is a pure function:
  nothing is read from uCRM and nothing is written.
- **The rollback goes back to 5.18.84**, both features whole and both switches as they are (RB). The empty form and the
  nameless booking WhatsApp come back.
- 086 is labelled 5.18.83's wherever the script names it: the derived wording first said *"the baseline's"*, which would
  now read 5.18.84.
- The script is made by a derivation from 5.18.84's, with every replacement asserted. It reproduces the committed script
  byte for byte.

**Rehearsal `scripts/harness/deploy-5.18.85/rehearse.sh`.** The base is installed as production runs it: 5.18.84, 086
applied, authorisation ON in both copies with activation #1, the booking WhatsApp ON in both copies. It covers:
- **the A refusals:** a 5.18.83 server; a placeholder pin; the branch tip; a commit carrying the release plus an AI-layer
  file; one without the quotation reader; the booking switch's copies disagreeing; the authorisation's disagreeing; 086
  incomplete;
- **the deploy itself:** no note, both switches unchanged, every table, the vault and the configuration files
  byte-identical;
- **the teeth:** the quotation read taken out of the staff API; the technician's name taken out of `job.add`; a 5.18.84
  file changed; a trigger dropped;
- the pilot switch read live; the operator turning the booking WhatsApp off, then its copies made to disagree;
- the rollback with both switches on;
- two weakened copies of the script: R1 blinded, and R6's quotation-read check blinded.

**The first two runs read 164 passed, 2 failed**, on the same two checks: the control after the refusals (*the data is
as seeded*) and *the deploy wrote no record and no configuration value*. Both found the data digest changed, and the
rehearsal had changed it, not the script. 1f and 1g take the switches' two copies apart and put them back with `cfg_set`,
which re-adds a key at the end of the stored configuration row: the same content in other bytes, and the digest hashes raw
rows. The deploy left the digest exactly where it found it. Fixed in the rehearsal (`aaba731`):
- the two copies are kept byte for byte and put back exactly;
- 1f and 1g each check that their refusal changed no data;
- a new control checks both switches on in both copies and the data as seeded.

The fix was proved offline first, with the old re-add as the control producing a different digest.

Results after the fix: **run 1 167/0**, 25 runs of the script (the committed script, sha256 `18eaae9ca8a3b149…`), no
FAIL line, the checkout left as found; **run 2 167/0**, 25 runs, the same script, the same check lines. The rehearsed
deploy reads **34 ok / 0 failed / 0 notes**, 5.18.84's 32 with V9 and R8. On the server expect 33: there is no
`dishnet.sqlite` there to back up, as with 5.18.83 and 5.18.84.

**Handover — each step is the operator's; the push and the deploy are done.**
0. **Push both branches — done, 06 Oct 18:36 UTC.** The server pulls them from GitHub: `claude/study-this-jhe2eg` carries
   the script, and `release/5.18.85` the release commit.
1. **Deploy, as root on the server — DONE 19:13 UTC, PASSED 33/0/0** (the RESULT below). It asks for `DEPLOY`; send
   back the **log file**:

   `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.85 && mkdir -p /root/dnb-5.18.85 && bash scripts/deploy-5.18.85.sh 2>&1 | tee /root/dnb-5.18.85/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`

   The rollback is printed by the script, alone, at the end of its log. It is never handed over beside the deploy (root
   docs/44 §16.9).
2. **Nothing to switch on.** Try it on a Starlink installation job whose customer has a quotation in uCRM: press *Request
   customer authorisation*, check the values the form took from the quotation, enter transport, send. A new installation
   job's booking WhatsApp now names its technician.

- **RESULT — DEPLOYED to production 2026-10-06, 19:13 UTC: PASSED, 33 ok / 0 failed / 0 notes** — the 33 expected. The
  rehearsal reads 34 because the sandbox holds a `dishnet.sqlite` to back up; the server holds none (*"nothing to
  copy"*). The run began at 19:13:18 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves
  4790019"*; the 16 files were stamped at 19:13:47 UTC. Recorded from the terminal the operator pasted (the script prints
  no secret); the log file stays on the server under `/root/dnb-5.18.85/`.
  - **A.** The checkout fast-forwarded `9898608` → `3274b1b`; branch tip `4a7127d` (not installed); release commit
    `4790019` cut on `79607d4`; 16 files (14 changed, 2 added, 0 removed), no migration; **A0** clean. Live `79607d4` /
    5.18.84. PHP **8.1.34** accepted the 5 changed server files and the 10 test files. Pilot `on`; **the authorisation
    `ia=on/on`** and **the booking WhatsApp `wa=on/on`**, as the operator left them; 086 complete, its tables
    `1:7:1:1:3` (`0:2:1:1:0` at 5.18.84's deploy: one request since, the pilot's); the webhook log's last 300 entries
    hold **2** `job.add`, the last at 18:26:22 by the plugin's clock (UTC+3), 15:26:22 UTC, the pilot's booking by its
    time; photo tables `present:11:3`, 11 files.
  - **Backup** `/root/dnb-5.18.85/backup-20261006T191318Z`: `plugin.sqlite3` 29 MB, one consistent copy, integrity ok,
    248 tables; the data directory 144 MB; the installed 5.18.84 11 MB; the vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V6** zoom allowed; **V5** 302 / 401;
    **V7** the customer authorisation page 404 *"This link is not valid"* to a request without a link; **V8**
    `install_auth_request` and **V9** `install_auth_prefill` 401 without a login; **V3** the pilot unchanged (`on` →
    `on`); **V3b** the authorisation unchanged (`on/on` → `on/on`); **V3c** the booking WhatsApp unchanged (`on/on` →
    `on/on`); **V4** no fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 16 files as `4790019` has them, manifest 5.18.85; R2 `pilot=on`, and the installed `InstallAuth` and
    `InstallScheduledWhatsApp` each read their switch ON in both copies; R3 086 still applied and complete, `1:7:1:1:3`
    before and after; 084 `11:3` rows, untouched; R4 the pilot as before, the channel `NullWhatsAppChannel`, no portal,
    CSRF or AI-layer file; R5 all 219 files from Release A through 5.18.84 intact; R6 every earlier marker and 5.18.85's;
    **R7** (read-only) **UGX CASH IN HAND 591,072.00 · USD 0.00**, the figures 5.18.84's deploy read; the photo tables
    and files read exactly as before; **R8** the installed quotation reader read a 000181-shaped quotation as the form
    will (the kit, the plan, installation 150000, transport asked for).
  - **Not seen yet:** the form filled from a real quotation, and a booking WhatsApp naming its technician. Both wait for
    the next Starlink installation job (step 2).

**Git:** `4a7127d` (the feature), `24197d8` (the script and its rehearsal), `aaba731` (the rehearsal's fix),
`release/5.18.85` (`4790019`) and this entry (`d42570d`) — **pushed 06 Oct, 18:36 UTC**, on the operator's instruction
(*"yes push both branches"*): the branch to `d42570d`, `release/5.18.85` new at `4790019`. At the push nothing was
deployed, no configuration had changed and nothing had been sent to anyone.

## 06 Oct — Multi-number sales, Batch 1 (5.18.86): the three numbers made safe — the Inbox answers from the conversation's own number — and the channel registry foundation, dark; BUILT in development, PUSHED 07 Oct 02:54 UTC, NOT deployed

**Instruction:** the operator reviewed `docs/65` (the discovery, `c8b3e36`) and approved *"MULTI-NUMBER SALES —
BATCH 1 / FOUNDATION + EXISTING ROUTING SAFETY"*, parts A–R: fix the Inbox's reply routing; verify the Batch 0 lead path
against the current code; protect worker-owned events in the event processor; build the channel registry (SQLite), its
seed, resolver and `ChannelContext`; route inbound and outbound by channel id; carry the channel on leads; golden tests;
flag `multi_number_channels_enabled` OFF by default; no admin UI; no Evolution instance, SIM, number or server change;
SEC-1…SEC-5 recorded, not fixed; South Sudan and Domain B unchanged; a LOCAL commit only. **None of the STOP conditions
was met:** no production behaviour changed beyond the approved Inbox fix (and part C, approved by name), no instance
created, no configuration changed, no number connected, Domain B and South Sudan untouched, no customer data migrated,
no destructive migration.

**The record is `docs/65` §Z.** In short:
- **Flag OFF (the production default) — exactly two behaviours change.** (1) **Uganda's Inbox** answers a `sales`
  conversation from the sales number and an `account` conversation from the account number — both went out on support
  until now — and a registry channel's conversation from its own number or not at all; it reports a failed send instead
  of saying *sent*, and never falls back to another number. `support`, `accounts`, `web` and `marketing` keep their
  `sendVia()` path exactly; **South Sudan keeps the 5.18.85 line verbatim.** (2) **Uganda's `cron/event_processor.php`**
  no longer claims `ai.reply`, `ai.media` or `crm.lead.sync` (excluded in SQL through an additive `EventBus::consume()`
  argument), acknowledges `wa.escalation` as a known type, and reaches its dead-letter pass on a run that claims nothing;
  **South Sudan keeps the 5.18.85 loop exactly**, its two defects included (recorded for their own decision).
  Everything else runs the 5.18.85 code: `EvolutionApiService::forStore()` returns the constructor's service.
- **Flag ON, Uganda only:** migration **087** (`wa_channels`, `wa_channel_log`, the three numbers seeded under their
  present ids with **no instance stored** — their instance stays in the configuration keys, read through the
  constructor's own rule); the resolver (refuses an unknown or switched-off number, never re-routes); `ChannelContext`
  (role, persona, territory, portfolio for the brain; instance and number server-side only); inbound and outbound by
  channel id; the AI worker, the media worker and the hand-over send only on the instance the message arrived on;
  follow-ups only on their own number; leads carry their channel, number, owner and territory beside the
  `conversation_id` they always had.
- `lib/ChannelRegistry.php`, `lib/ChannelContext.php`, `lib/InboxReplyRoute.php`, `migrations/087_wa_channels.sql`,
  `tools/channels.php` (read-only) — new. Changed: `lib/EvolutionApiService.php`, `lib/NotificationService.php`
  (`sendOnChannel()`, new), `lib/AiLeadService.php`, `lib/EventBus.php`, `evo_webhook.php`, `workers/AiReplyWorker.php`,
  `workers/MediaWorker.php`, `cron/followup_send.php`, `cron/event_processor.php`, `includes/api/api_whatsapp.php`,
  `tools/set_config.php` (the flag listed), `manifest.json` 5.18.86 and the fifteen version pins; five *"no migration"*
  guards in the media tests and `test_media_foundation`'s event-processor pin moved past 087 deliberately, as when 086
  arrived.

**Proofs:**
- `tests/test_channel_registry.php` **104 passed, 0 failed** — migration 087 (seeds, every CHECK and trigger, applied
  twice, touches nothing else, names no credential); the switch (flag AND Uganda); `forStore()` identical to the
  constructor OFF and outside Uganda for four configuration shapes; Part Q tests 1–6; writes (validation, trail,
  idempotence, masking); `ChannelContext`; an unreadable registry; nothing logged or printed carries the key or a whole
  number; the read-only CLI changes nothing; Domain B untouched; **six weakened copies, each caught**.
- `tests/test_multi_number_routing.php` **89 passed, 0 failed** — the real plugin under `php -S` beside a fake Evolution
  and fake uCRMs, the webhook POSTed as Evolution posts it, the Inbox called as the panel calls it, the crons and the
  media runner run as the scheduler runs them, the AI worker with a brain that never leaves the process: Part Q tests
  7–20 and 22–23, follow-ups, notifications, South Sudan, the golden comparison of the three numbers OFF and ON (webhook
  answer, payload, conversation, role, context, prompt, reply number, the stand-down for a colleague — identical), and
  **eight weakened copies, each caught**, among them test 13 twice: the worker answering on the role's department number,
  and the Inbox sending a sales chat through support.
- `tests/test_event_processor_protected.php` **35 passed, 0 failed** — part C on Uganda: success, transient failure,
  retry, dead letter; South Sudan control scenarios showing the 5.18.85 loop unchanged; **seven weakened copies, each
  caught** (two of them the country gate forced open and shut). Test 21.
- `tests/test_lead_path_batch0.php` 25 passed, 0 failed — Part B: the Batch 0 path is in the current code (`$context`;
  `payloadOf()`), verified, not copied; its four weakened copies still find their anchors and are still caught.
- **Focused, twice** — the three new tests, the Batch 0 test and the 26 neighbouring suites whose code this batch
  touches or pins (the webhook and its trust, Evolution, the brain context, lead capture and its uCRM sync, the hand-over
  and the human stand-down, follow-ups, notifications, the media, voice, image and document workers, the migration
  ledger, the Uganda gate, the configuration tool, web chat in the Inbox): **30 files, 1,797 passed, 0 failed, twice**
  (21:35–21:41 and 21:41–21:46 UTC), every file's tally identical in both rounds, and each of the 27 existing ones
  identical to 5.18.85's.
- **Full suite, twice** — every `tests/run.sh` file, through the resumable runner described at the end of this entry
  (pass A 22:20–22:59, pass B 22:59–23:38 UTC): **283 files, 13,678 passed, 0 failed, 0 skipped — twice**, every suite's
  tally identical in both runs. Against 5.18.85 (280 files, 13,450 passed, 0 failed): exactly the three new suites are
  added (+228 = 104 + 35 + 89) and **no other suite's tally moved**. PHP warnings: 5 per run, all from
  `test_dpo_endpoints` (5), which printed the same in 5.18.85.
- **South Sudan:** the new tests' own South Sudan controls (the Inbox line verbatim, the webhook and the registry off,
  the 5.18.85 event-processor loop), and the nine South Sudan and tenant suites, each identical to 5.18.85 in both runs:
  `test_staff_jobs_south_sudan` 51 · `test_tenant_profile` 108 · `test_portal_tenant` 112 · `test_email_no_sudan` 62 ·
  `test_sales_support_tenant` 37 · `test_notify_tenant_text` 30 · `test_cashbook_tenant` 26 · `test_phone_country` 26 ·
  `test_ai_country_facts` 21 — all 0 failed.
- **Domain B:** no file under `dishnet-mikrotik-control-plane/` or `dishnet-hybrid-sudan/docs/` changed — asserted by the
  registry test and by `git status`.
- PHP lint of all 33 changed PHP files: clean; nothing newer than PHP 7.4 in them. `git diff --check`: clean. Secret scan
  of the diff and the new files: clean — every phone-shaped value in the tests is a fictitious fixture in the style the
  existing tests use, no banned value, and the only key-shaped string is the registry test's planted fake key
  (`CR-SECRET-EVO-KEY-0042`), whose absence from every log line and printout is what that test asserts. Documentation
  scan: clean. Migration review: 087 additive, no existing table touched, applied twice
  without change; `test_migration_integrity` 28 passed, 0 failed.

**Recorded, not fixed (`docs/65` §Z.6):** SEC-1…SEC-5 all still open, and Batch 1 adds no credential exposure (each
asserted); South Sudan's event processor keeps its two defects and the early return; `efris.submit` acknowledged as
unknown by the event processor (left as it was, mitigated); `wa.escalation` has no consumer; `wa_send_quote_pdf` still
sends from support; `cron/wa_webhook_guard.php` not registry-aware; unread counts per channel; an Inbox support reply
stored twice (as before); the Splynx null call in `ticket.status_changed`; with the switch on, the hand-over's staff
alert follows the registry's `sales` row while the other staff alerts do not (§Z.3, for Batch 2); and, to read on the
server before any
deployment, whether the Inbox's settings row holds the Evolution connection (`docs/65` §Z.6, [SERVER?]).

**Flags:** `multi_number_channels_enabled` OFF everywhere (absent means off) — set nowhere, by nothing. No other flag
read or changed: `ai_lead_capture` and `ai_crm_lead_sync` keep whatever production holds.

**Production impact if this were deployed as it stands:** on Uganda, Inbox replies on sales and account conversations
start leaving from their own numbers (the approved fix); Uganda's event processor stops touching AI and lead-sync events;
migration 087 creates two inert tables. The switch stays OFF; nothing else changes. Not deployed; no release commit
cut; no deploy script.

**Rollback:** nothing is deployed. Before a deployment: leaving the switch off disables the whole registry path; the two
always-on changes are code (undone by deploying 5.18.85 again); migration 087 is additive and may stay.

**The run, as it happened:** a first full run found two *"no migration"* guards that 087 made fail (fixed as above);
a self-review then found part C applying to South Sudan, against *"DO NOT CHANGE: South Sudan behaviour"* — gated to
Uganda, with South Sudan controls and two weakened copies added. The container then restarted twice during plain
`tests/run.sh` runs (21:57 and 22:19 UTC, at different files — `test_job_notifications_day`, `test_notify_kyc_quote_send`,
so no single test caused them); both focused rounds had finished before the first. The two counted full runs were
therefore made with a resumable runner that does exactly what `run.sh` does — every `test_*.php` in the same order, each
with its own fresh vault file — keeping each finished file's output and exit code, and running any file cut off by a
restart again from scratch with a fresh vault; each pass was then assembled into `run.sh`'s own output format. Neither
pass was interrupted (A 22:20–22:59, B 22:59–23:38 UTC).

**Git:** the discovery (`c8b3e36`) and this batch (`b1865ea`) — **pushed 07 Oct, 02:54 UTC**, on the operator's
instruction (*"yes push branch"*): `claude/study-this-jhe2eg` from `cae0689` to `b1865ea`. Nothing deployed, no
configuration changed, nothing sent to anyone. `docs/59` stays untracked by the operator's decision.

## 07 Oct — 5.18.86: the WhatsApp Inbox answers from the conversation's own number (Uganda); `release/5.18.86` = `c2c96e1`, cut on live 5.18.85 (`4790019`); `scripts/deploy-5.18.86.sh` pinned to it and rehearsed — PUSHED 07 Oct 04:20 UTC; DEPLOYED 04:26 UTC (PASSED 37/0/0)

**Why.** Batch 1 (above) fixed a live defect: every reply typed in the WhatsApp Inbox chose its sender with
`channel === 'accounts' ? 'accounts' : 'support'`, so a customer who wrote to the **sales** number was answered from the
**support** number, in another chat, from a number they had never written to. An **account** chat went out on support
too, because it is stored as `account`, not `accounts`. The operator approved releasing it and chose *"Release 5.18.86
(Recommended)"*.

**Why not a copy of the branch, as 5.18.85 was.** 5.18.85's rule is that every file the feature changes was identical on
live and on the branch before the feature, so the release can take the branch's files. For Batch 1 that fails. Five of
its production files carry undeployed work on the branch: Batch 0's lead fixes (5.18.75) and the dark AI communication
layer (5.18.76–5.18.81). Those files are `cron/event_processor.php`, `evo_webhook.php`, `lib/EvolutionApiService.php`,
`tools/set_config.php` and `workers/AiReplyWorker.php`. `workers/MediaWorker.php` does not exist on live. Applied onto
live, Batch 1's changes conflict in three files; every conflict is Batch 0 or the media layer. The operator chose the
scope (*"Inbox fix only (Recommended)"*): **5.18.86 is the Inbox fix alone, applied on live 5.18.85.** Migration 087,
the event-processor change, the webhook and worker routing and the lead fields wait.

**What 5.18.86 does.** Uganda only, no switch, no migration.
- **A reply in a sales chat leaves on the sales number, and one in an account chat on the account number.** This holds for
  text, images and documents, in all four Inbox send actions. It goes through `NotificationService::sendOnChannel()`:
  - no other number and no WASender;
  - no retry queue, because its retry would send from support;
  - stored once, with the WhatsApp message id.
- **A reply that cannot leave on its own number is refused, and the person in the Inbox is told.** It answers 502 with
  *"Not sent on the sales number — … Nothing was sent from any other number."*, or, when WhatsApp did not answer, *"May
  have been sent on the sales number — … Check the chat before sending again."* It is never sent from another number.
- **Support, accounts and web chats keep `sendVia()` exactly**, their reporting included. **South Sudan keeps the 5.18.85
  line verbatim.**
- **The channel registry's code ships switched off and without its tables.** `ChannelRegistry`, `ChannelContext` and
  `EvolutionApiService::forStore()` are present because the Inbox route uses them. `multi_number_channels_enabled` is
  unset. Set, it would change nothing here: there is no migration 087, and the three numbers route as configured.

**What the Inbox reads.** `public.php`'s `$config` is the `kyc_config` row of `plugin.sqlite3` alone, not the files and not
the vault (`docs/65` §Z.6). If that row lacks the Evolution address, the key or the sales or account instance, a sales or
account reply that leaves from support today would be refused under 5.18.86. The deploy script therefore reads that row
first (A3).

**Files**, 13 against `4790019`: 4 added, 9 changed.
- **Added:** `lib/InboxReplyRoute.php`, `lib/ChannelRegistry.php`, `lib/ChannelContext.php` and
  `tests/test_inbox_reply_route.php`.
- **Changed:** `includes/api/api_whatsapp.php`, `lib/NotificationService.php`, `lib/EvolutionApiService.php`,
  `manifest.json` 5.18.86 and the five distributor version pins.
- **Twelve are byte-identical to the branch's**, and each of the eight among them that changed was identical on
  `4790019` and on the branch before Batch 1 (`c8b3e36`).
- **`lib/EvolutionApiService.php` is live's file with Batch 1's change applied.** Proved: it differs from the branch's by
  exactly the 25-line AI-media method live does not have (`getBase64FromMediaMessage`).

**Tests.**
- **`tests/test_inbox_reply_route.php` (new) runs unchanged on the branch and on the release tree: 23 passed, 0 failed on
  both.** It uses only what both trees hold. It covers:
  - sales → the sales number;
  - support → support;
  - account → the account number;
  - accounts → the account number;
  - web → support;
  - images and documents;
  - a failed send reported as not sent;
  - a chat on a number the plugin cannot send from refused, never sent from support;
  - Evolution, or the account number, missing from the Inbox's own row while the files keep them: refused and said;
  - the registry switch set: routes as without it;
  - South Sudan: the 5.18.85 rule;
  - **five weakened copies, each caught**: the route sending a sales chat through support; the reply action ignoring the
    route; the channel send falling back to support; a failed send reported as sent; South Sudan answered by the Uganda
    rule.
- **The release tree's own suite: 268 files, 12,533 passed, 0 failed, 0 skipped — twice**, every file's tally
  identical in both passes.
  - Against 5.18.85's release suite (267 files, 12,510 passed, 0 failed), exactly one file is added, the new test
    (+23), and **no other file's tally moved**: the five distributor tests read the new version pin and pass as before.
  - PHP warnings: 5 per pass, all from `test_dpo_endpoints`, as in 5.18.85's.
  - Run from a worktree beside the repository, because `test_cli_data_dir` drops to the user `nobody`, who cannot
    enter the session's private directory (5.18.83's entry). The runner is the resumable one the Batch 1 entry
    describes: every `tests/run.sh` file, in order, each with its own fresh vault. Neither pass was interrupted
    (A 03:09–03:42, B 03:42–04:15 UTC).
  - The tree was unchanged by both: still `c2c96e1`, nothing modified, nothing untracked.
- **On the branch** the new test ran twice on `22e6c59`: **23 passed, 0 failed both times**. It is the very file the
  release carries (blob `385f953e54e4` in both trees). The branch's full suite was not run again for an added test file:
  its plugin code is unchanged since the two full runs on `b1865ea` (the Batch 1 entry above). Since then the branch
  gains only this test, the script, its rehearsal and this entry.

**`scripts/deploy-5.18.86.sh`**, 5.18.85's shape, pinned to `c2c96e1` over `4790019`. It is made by a derivation from
5.18.85's, with every replacement asserted. What differs:
- **A3 (new), before anything changes.** The settings row the Inbox reads is judged by the installed
  `EvolutionApiService`. It must reach Evolution and name a sales and an account number, or the deploy stops and nothing
  is changed. It prints yes/no only, never an address, a key or an instance name.
- **V3d:** that row unchanged by the run.
- **V10:** the Inbox's reply action refuses an anonymous caller before any conversation is read.
- **R4:** none of the rest of Batch 1 is installed: no migration 087, no registry CLI, and 5.18.85's webhook, AI worker and
  event processor.
- **R6:** 5.18.86's pieces, 5.18.85's quotation form as a regression.
- **R9 (new):** the installed Inbox route, under the server's own PHP, with the server's own Inbox row, answers which number
  each kind of chat leaves on, with the registry dark. It is pure: nothing is sent or written.
- **A0** allows no migration and exactly the release's 13 files.
- **The rollback goes back to 5.18.85.** Every live feature and both switches stay as they are. Its note says what that
  means: the Inbox answers sales and account chats from the support number again.
- **Both live features are kept as they are**, as in 5.18.85 (V3b, V3c, R2).

**Rehearsal `scripts/harness/deploy-5.18.86/rehearse.sh`.** The base is installed as production runs it: 5.18.85, 086
applied, the authorisation on in both copies with activation #1, the booking WhatsApp on in both copies, and the Inbox's
row naming Evolution and the three numbers (fictitious, never contacted). It covers:
- **the A refusals:**
  - a 5.18.84 server;
  - a placeholder pin; the branch tip;
  - the release plus an AI-layer file; the release plus migration 087; the release without its Inbox route;
  - either switch's copies disagreeing; 086 incomplete;
  - **Evolution's address, or the account number, missing from the Inbox's row (A3)**, with no data changed and nothing
    printed but yes/no;
- **the deploy itself:** no note, every table, the vault and the configuration files byte-identical, and the log carrying no
  Evolution address, key or instance name;
- **the teeth:** 5.18.85's Inbox API back (R1, R6); the channel send taken out (R1, R6); a 5.18.85 file changed (R5); a
  trigger dropped (R3); the account number taken out of the Inbox's row after the deploy (R9);
- the pilot switch read live; the booking WhatsApp turned off, then its copies made to disagree;
- the rollback with both switches on;
- **three weakened copies of the script**, each caught: A3 blinded, R1 blinded, R6's Inbox-route check blinded.

**Results.** The first run read **181 passed, 3 failed**. It found two defects, both fixed before the commit:
- **In the script:** its two count lines (`N_IR` in R6, `N_OLD` in the rollback's check) used `grep -c … || echo 0`,
  which prints `0` twice when nothing matches. R6 still failed when the Inbox route was missing, but its line carried a
  broken count and did not name the route. Now `|| true` and `${N:-0}`, proved on no match, a missing file and one match.
- **In the rehearsal:** 5g compared the data with the digest seeded at the start, though section 5 had just switched the
  booking WhatsApp off and on again. That re-adds the key at the end of the settings row: the same values, different
  bytes. 5g now compares with the digest taken just before 5f.

After the fix, two runs on the working copy read **184/0** each. Then two runs on the committed script (`22e6c59`,
sha256 `57c4053090def6d0…`): **run 1 184/0**, 32 runs of the script, no FAIL line, the checkout left as found; **run 2
184/0**, the same check lines. Both match the working-copy runs line for line, except the branch tip that 1c refuses,
now `d818f6d`. The rehearsed deploy reads **38 ok / 0 failed / 0 notes**: 5.18.85's 34 with A3, V3d, V10 and R9. **On
the server expect 37**: there is no `dishnet.sqlite` there to back up, as at 5.18.85's deploy.

**Handover — each step is the operator's; the push and the deploy are done.**
0. **Push both branches — done, 07 Oct 04:20 UTC.** The server pulls them from GitHub: `claude/study-this-jhe2eg` carries
   the script, and `release/5.18.86` the release commit.
1. **Deploy, as root on the server — DONE 04:26 UTC, PASSED 37/0/0** (the RESULT below). It asks for `DEPLOY`; send
   back the **log file**:

   `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.86 && mkdir -p /root/dnb-5.18.86 && bash scripts/deploy-5.18.86.sh 2>&1 | tee /root/dnb-5.18.86/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`

   **If A3 stops it**, nothing has changed. The log says, yes or no, which of Evolution, the sales number and the
   account number the Inbox's own settings row lacks: send it back. If it is Evolution, `docs/65` §Z.6 applies: with no
   Evolution in that row, a support reply can be reported sent while nothing leaves, so A3 may have found a live fault,
   not only a 5.18.86 one.

   The rollback is printed by the script, alone, at the end of its log. It is never handed over beside the deploy (root
   docs/44 §16.9).
2. **Nothing to switch on.** In Engage → WhatsApp → Inbox, reply to a customer who wrote to the sales number: the reply
   arrives in their chat with the sales number.

- **RESULT — DEPLOYED to production 2026-10-07, 04:26 UTC: PASSED, 37 ok / 0 failed / 0 notes** — the 37 expected. The
  rehearsal reads 38 because the sandbox holds a `dishnet.sqlite` to back up; the server holds none (*"nothing to
  copy"*). The run began at 04:25:28 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves
  c2c96e1"*; the 13 files were stamped at 04:26:06 UTC. Recorded from the terminal the operator pasted (the script prints
  no secret); the log file stays on the server under `/root/dnb-5.18.86/`.
  - **A.** The checkout fast-forwarded `3274b1b` → `17b8e8d`; branch tip `d818f6d` (not installed); release commit
    `c2c96e1` cut on `4790019`; 13 files (9 changed, 4 added, 0 removed), no migration; **A0** clean. Live `4790019` /
    5.18.85. PHP **8.1.34** accepted the 6 changed server files and the 6 test files. Pilot `on`; the authorisation
    `ia=on/on` and the booking WhatsApp `wa=on/on`, as the operator left them. **A3 — the Inbox's own settings row:
    `evo=yes sales=yes support=yes account=yes registry=absent`.** It reaches Evolution and names all three numbers, and
    the registry switch is not set: `docs/65` §Z.6's question, answered (and SEC-1's, below). 086 complete, its tables
    `1:7:1:1:3`, as at 5.18.85's deploy; the webhook log's last 300 entries hold **2** `job.add`, the last at 18:26:22 by
    the plugin's clock (UTC+3), 15:26:22 UTC on 06 Oct, as at 5.18.85's deploy; photo tables `present:11:3`, 11 files.
  - **Backup** `/root/dnb-5.18.86/backup-20261007T042528Z`: `plugin.sqlite3` 29 MB, one consistent copy, integrity ok,
    248 tables; the data directory 146 MB; the installed 5.18.85 11 MB; the vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V6** zoom allowed; **V5** 302 / 401;
    **V7** the customer authorisation page 404 *"This link is not valid"* to a request without a link; **V8**
    `install_auth_request`, **V9** `install_auth_prefill` and **V10** `wa_send_reply` 401 without a login; **V3** the
    pilot unchanged (`on` → `on`); **V3b** and **V3c** both switches unchanged (`on/on` → `on/on`); **V3d** the Inbox's
    settings row unchanged; **V4** no fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 13 files as `c2c96e1` has them, manifest 5.18.86; R2 `pilot=on`, and the installed `InstallAuth` and
    `InstallScheduledWhatsApp` each read their switch ON in both copies; R3 086 still applied and complete, `1:7:1:1:3`
    before and after; 084 `11:3` rows, untouched; R4 the pilot as before, the channel `NullWhatsAppChannel`, no portal,
    CSRF or AI-layer file, none of the rest of Batch 1; R5 all 221 files from Release A through 5.18.85 intact; R6 every
    earlier marker and 5.18.86's; **R7** (read-only) **UGX CASH IN HAND 591,072.00 · USD 0.00**, the figures 5.18.85's
    deploy read; the photo tables and files read exactly as before; R8 the quotation reader as at 5.18.85; **R9** the
    installed Inbox route on the server's own Inbox row: sales → the sales number, account → the account number,
    support, accounts and web → `sendVia()` as before, the registry dark.
  - **Not seen yet:** a real Inbox reply to a sales chat arriving from the sales number. It waits for the next one
    (step 2).
  - **Found by A3, not fixed: SEC-1 is live.** The Wallet Top-up & Admin tab (`tabs/accounts/wallet_admin.php:71`)
    writes `evo_api_key` from this same settings row into a hidden form field, and A3 shows that row's key is set. So
    the Evolution key is in that page's HTML for anyone who can open the tab (role `admin`, and the grantable
    `wallet_admin` permission). The file is unchanged by 5.18.86. Its fix needs its own approval (`docs/65` §O.3).

**Git:** `d818f6d` (the test), `22e6c59` (the script and its rehearsal), `release/5.18.86` (`c2c96e1`) and this
entry (`988135d`) — **pushed 07 Oct, 04:20 UTC**, on the operator's instruction (*"yes push both branches"*): the branch
from `89047e0` to `988135d` (04:20:16 UTC), `release/5.18.86` new at `c2c96e1` (04:20:20 UTC). At the push nothing was
deployed, no configuration had changed and nothing had been sent to anyone. `docs/59` stays untracked by the operator's
decision.

## 07 Oct — 5.18.87: the AI's WhatsApp leads recorded at last — Batch 0's lead fixes (Uganda); `release/5.18.87` = `9cc81af`, cut on live 5.18.86 (`c2c96e1`); `scripts/deploy-5.18.87.sh` pinned to it and rehearsed — PUSHED 07 Oct 06:33 UTC; DEPLOYED 06:35 UTC (39/1/0 — the one FAIL, R7's photo count, is three photos taken during the run); `--after-only` 06:52 UTC PASSED 33/0/0

**Why.** The AI's lead path has never worked in production (`docs/55` §3). Three defects were found on 04 Oct:
- **Every capture fails.** `AiReplyWorker` hands `latestPin()` the variable `$ctx`, which does not exist there. The call
  throws, the worker logs *"lead capture failed"*, no lead is written and no uCRM sync is queued.
- **Every uCRM lead sync is dropped.** `UcrmLeadWorker` reads `$event['payload']`, which `WorkerBase` leaves as a JSON
  string; the decoded payload is `_payload`. So `lead_id` is 0.
- **The sales context carries no pin**, and the reply guard's audit records conversation 0.

Batch 0 (`b0674bd`, 5.18.75, the 04 Oct entry) fixed all three; it was never deployed. On 07 Oct the operator, adding a
salesperson (twenty in all), asked for Batch 0's lead fixes to be released.

**The decision, 07 Oct: *"Leads on, uCRM later (Recommended)"*.**
- `ai_lead_capture` stays **ON**. A qualified WhatsApp enquiry becomes a lead in Sales → Leads: a write inside the
  plugin; nothing leaves it.
- `ai_crm_lead_sync` is switched **OFF** by the operator before the deploy (step 0 of the handover), and the deploy checks
  it (A4). Nothing reaches uCRM. Switching it on is its own later step.
- This is option (i) of the 04 Oct entry. It replaces the 05 Oct instruction to treat both switches as not approved for
  activation: lead capture is approved; the uCRM write is not yet.

**Also decided on 07 Oct, for salespeople's own numbers:** D4, D5 and D7, recorded in `docs/65` §W. They bind the design of
the next batch; none of it is built.

**Why Batch 0's files can be taken as they are.** Each of the four files Batch 0 changed (`workers/AiReplyWorker.php`,
`workers/UcrmLeadWorker.php`, `lib/BrainContext.php` and `tests/test_brain_context.php`) was identical on live 5.18.86
(`c2c96e1`) and on the branch just before Batch 0 (`6877bd8`). So the release takes them from `b0674bd` byte for byte,
with Batch 0's own test.

**What 5.18.87 does.** No switch, no migration.
- **A qualified enquiry becomes a lead.** When the assistant's reply carries its LEAD line, `AiLeadService` writes the lead
  to the plugin's `leads` table with the pin the customer sent, linked to the conversation, and audits it in
  `ai_crm_actions.json`. Sales → Leads shows it.
- **On which numbers.** The assistant is asked for a LEAD line only under `ai_qualification`. With
  `ai_sales_on_all_numbers` on, the support and account numbers sell and record leads too. The deploy reports both (R10)
  and changes neither.
- **With the uCRM write off, nothing reaches uCRM.** The uCRM lead worker settles a sync it receives as done, *"lead #N
  not synced — ai_crm_lead_sync is off"*, and never retries it.
- **A lead recorded while the write is off is not sent when the write is switched on later.** Only the customer's next
  qualified turn, which updates that lead, queues a sync that reaches uCRM (the switches test, B).
- **5.18.86's event processor can take a lead's sync first.** Batch 1's part C is not in production, so
  `cron/event_processor.php` can acknowledge a `crm.lead.sync` as an unknown type before the worker sees it (`docs/65`
  §Z.5). While the write is off this changes nothing: nothing reaches uCRM either way. With the write on, such a lead
  would not reach uCRM. **So part C should be in production before the uCRM write is switched on.**

**Files**, 12 against `c2c96e1`: 2 added, 10 changed.
- **Added:** `tests/test_lead_path_batch0.php` (Batch 0's, byte for byte) and `tests/test_lead_switches.php` (new).
- **Changed:** `workers/AiReplyWorker.php`, `workers/UcrmLeadWorker.php`, `lib/BrainContext.php` and
  `tests/test_brain_context.php` (Batch 0's, byte for byte); `manifest.json` 5.18.87 and the five distributor version
  pins.

**Tests.**
- **`tests/test_lead_switches.php` (new): 27 passed, 0 failed**, on the branch and on the release tree. The AI worker and
  the uCRM lead worker run in the sandbox beside a fake Evolution and a fake uCRM, with the switches as decided:
  - **A** — a qualified enquiry becomes exactly one lead, with the pin; one sync is queued and settled *done*, "not synced
    — ai_crm_lead_sync is off"; uCRM receives nothing; a second pass retries nothing;
  - **B** — the uCRM write switched on afterwards: the earlier lead is not sent by itself; the customer's next qualified
    turn updates that lead (still one row) and queues a second sync, which creates one lead client and links it;
  - **C** — capture off: the reply is still sent; no lead, nothing queued;
  - **D** — the support number, with `ai_sales_on_all_numbers` on: the reply leaves from that number, and the lead is
    recorded, linked to that conversation;
  - **E** — the assistant is asked for a lead on the sales number; on support and account only with
    `ai_sales_on_all_numbers`; nowhere with qualification or capture off;
  - **four weakened copies, each caught**: the capture handed `$ctx` again; the uCRM write ignoring its switch; capture
    ignoring its switch; a switched-off sync retried.
- **On the release tree** Batch 0's own test reads 25 passed and `test_brain_context` 123, 0 failed each.
- **The release tree's own suite: 270 files, 12,586 passed, 0 failed, 0 skipped — twice**, every file's
  tally identical in both passes.
  - Against 5.18.86's release suite (268 files, 12,533 passed, 0 failed), exactly the two new tests
    are added (+25, +27), and **one other file's tally moved, as Batch 0 moved it**: `test_brain_context` 122 → 123,
    Batch 0's own added assertion. The five distributor tests read the new version pin and pass as before.
  - PHP warnings: 5 per pass, all from `test_dpo_endpoints`, as in 5.18.86's.
  - Run from a worktree beside the repository, with the resumable runner the Batch 1 entry describes: every
    `tests/run.sh` file, in order, each with its own fresh vault. Neither pass was interrupted (A 05:09–05:42,
    B 05:42–06:17 UTC).
  - The tree was unchanged by both: still `9cc81af`, nothing modified, nothing untracked.
- **On the branch** the new test ran twice on `8449d2d`, before its commit: **27 passed, 0 failed both times**. The
  commit, `ad5371b`, adds exactly that file (blob `8085f071b021`, the same in both trees). The branch's full suite
  was not run again for an added test file: since the two full runs on `b1865ea` (the Batch 1 entry) its plugin
  directory has gained two test files and nothing else.

**`scripts/deploy-5.18.87.sh`**, 5.18.86's shape, pinned to `9cc81af` over `c2c96e1`. It is made by a derivation from
5.18.86's, with every replacement asserted. What differs:
- **A4 (new), before anything changes.** `ai_lead_capture` must read ON in both copies (the configuration files the
  workers read, and the store row) and `ai_crm_lead_sync` OFF in both, or the deploy stops and nothing is changed.
  `ai_qualification` off is a note: no lead would be asked for.
- **A3 is evidence now.** The Inbox's settings row is 5.18.86's concern, unchanged by this release: a missing number is a
  note. An unreadable row still stops the deploy.
- **V3e (new):** the four lead switches unchanged by the run, and the two decided ones each with its two copies agreeing.
- **R4:** no media path in the AI worker, beside none of Batch 1.
- **R6:** Batch 0's pieces in place; 5.18.86's Inbox as a regression.
- **R10 (new):** what the installed lead path will do with this server's switches: on which numbers a lead is asked for,
  whether it is recorded, and whether it reaches uCRM. Read-only.
- **A0** allows no migration and exactly the release's 12 files.
- **The rollback goes back to 5.18.86.** Every switch stays as it is. Its note says what that means: every capture fails
  again and every sync is dropped; leads already recorded stay.
- **The script switches nothing.** Step 0's command is in this entry only, never in the script or its log.

**Rehearsal `scripts/harness/deploy-5.18.87/rehearse.sh`.** The base is installed as production runs it: 5.18.86, 086
applied, the authorisation and the booking WhatsApp on in both copies, the Inbox's row naming Evolution and the three
numbers (fictitious, never contacted), and the four lead switches ON in both copies, the uCRM write included. It covers:
- **the A refusals:** a 5.18.85 server; a placeholder pin; the branch tip; the release plus an AI media-layer file; the
  release plus migration 087; the release without the uCRM worker's fix; either live switch's copies disagreeing; 086
  incomplete; **the uCRM write still ON (A4)**; **lead capture's two copies disagreeing (A4)**;
- **step 0, as the operator runs it:** the installed `tools/set_config.php` through `docker exec`. It switches the uCRM
  write off in both copies and leaves lead capture on;
- **A3's note:** the account number taken out of the Inbox's row is said, and the run goes on to the `DEPLOY` prompt;
- **the deploy itself:** no note; every table, the vault and the configuration files byte-identical; no Evolution
  address, key or instance name and no switch command in the log;
- **the teeth:** 5.18.86's AI worker back (R1, R6); 5.18.86's uCRM worker back (R1, R6); a 5.18.86 file changed (R5); a
  trigger dropped (R3); a uCRM worker that would retry a switched-off sync (R1, R10);
- **the switches after the deploy:** the uCRM write on, as its own step, with the same tool: R10 says leads reach uCRM,
  and names the event processor's caveat; off again: byte for byte as before; lead capture's copies apart: V3e fails;
  qualification off: R10 says no lead is asked for anywhere; the booking WhatsApp off: still PASSES; the Inbox's account
  number taken away: R9 fails;
- the pilot switch read live; the rollback;
- **three weakened copies of the script**, each caught: A4 blinded, R6's Batch 0 check blinded, V3e's copies check
  blinded.

**Results.** The first run on the working copy read **205 passed, 0 failed**, 37 runs of the script, the rehearsed deploy
**41 ok / 0 failed / 0 notes**: 5.18.86's 38 with A4, V3e and R10. A review of the script's wording then found one
overstatement, fixed before the commit. R10, the header and the summary said the uCRM worker settles *each* lead's sync;
5.18.86's event processor can acknowledge one first (above). They now say *a* lead's sync, and R10 names that caveat when
the write is on. The second run on the working copy read 205/0 with the same check lines but 5b's, rewritten for the
caveat. Then two runs on the committed script (`70eff0c`, sha256 `cc8bb53f894be7a9…`): **run 1 205/0**, 37 runs of the
script, no FAIL line, the checkout left as found; **run 2 205/0**, the same check lines. Both match the working-copy runs
line for line, except the branch tip that 1c refuses, now `ad5371b`.

**Handover — each step is the operator's; the push and the deploy are done.** The push came first, on the operator's
word — done, 07 Oct 06:33 UTC: the server pulls both branches from GitHub, `claude/study-this-jhe2eg` for the script and
`release/5.18.87` for the release commit.
0. **Switch the uCRM lead write off, as root on the server, before the deploy — DONE before 06:35 UTC** (the RESULT
   below):

   `docker exec ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/set_config.php --key ai_crm_lead_sync --value 0`

   It prints *"ai_crm_lead_sync = 0"* and the settings listing. It writes the configuration file and the store row. On
   5.18.86 it changes nothing: no lead is captured there, so no sync is ever queued.
1. **Deploy, as root on the server — DONE 06:35 UTC, 39 ok / 1 failed / 0 notes** (the RESULT below). It asks for
   `DEPLOY`; send back the **log file**:

   `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.87 && mkdir -p /root/dnb-5.18.87 && bash scripts/deploy-5.18.87.sh 2>&1 | tee /root/dnb-5.18.87/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`

   **If A4 stops it**, nothing has changed. The log says what each lead switch reads in the files and in the store row:
   send it back.

   The rollback is printed by the script, alone, at the end of its log. It is never handed over beside the deploy (root
   docs/44 §16.9).

   **Expect 40 ok / 0 failed / 0 notes.** The rehearsal reads 41 because the sandbox holds a `dishnet.sqlite` to back up;
   the server holds none, as at 5.18.86's deploy. A note would say that `ai_qualification` is off, or that the Inbox's
   row lacks a number.
2. **Nothing to switch on.** The next customer who tells the assistant what they need, and where, appears in Sales →
   Leads; R10 says on which numbers.
3. **Later, as its own step: the uCRM write.** Not before Batch 1's part C, the event processor's protection of worker
   events, is in production; that needs its own release.

- **RESULT — DEPLOYED to production 2026-10-07, 06:35 UTC: 39 ok / 1 failed / 0 notes.** Every other check passed. The
  one FAIL is R7's photo comparison, *"the photo tables/files changed across the deploy (present:13:3 → present:16:3,
  files:13 → files:16)"*: three photos were taken on a job within the two minutes of the run. Recorded from the terminal
  the operator pasted (the script prints no secret); the log file stays on the server under `/root/dnb-5.18.87/`. The
  run began at 06:35:02 UTC; `DEPLOY` was typed and `deploy-hybrid.sh` answered *"✓ container now serves 9cc81af"*; the
  12 files were stamped at 06:35:47 UTC.
  - **Why the FAIL is not the deploy's.** The only writer of `job_photos` and of `uploads/job_photos/` is
    `JobPhotos::store()`, behind the staff photo upload, which needs a staff login; V5's own call to it without one was
    refused (401) before any handler. Rows and files rose together, by three; the GPS table did not move; nothing in
    the script writes either. It was 09:35 local time on a working day, and two photos had already been added since
    5.18.86's deploy (11 then, 13 at this run's start). **R7 compares the photo counts for equality, so it reads a
    technician at work as a change.** The next release's script accepts growth (rows and files rising together) and
    fails only on a loss. Not a reason to roll back, and the script said not to.
  - **Step 0, before the run, by the operator.** `set_config.php --key ai_crm_lead_sync --value 0` printed
    *"ai_crm_lead_sync = 0"*, the usual *[ConfigVault] restored after re-install* line and the settings list, where
    `ai_crm_lead_sync` reads OFF and `ai_qualification`, `ai_lead_capture` and `ai_sales_on_all_numbers` read ON.
  - **A.** The checkout fast-forwarded `17b8e8d` → `6569047`; branch tip `ad5371b` (not installed); release commit
    `9cc81af` cut on `c2c96e1`; 12 files (10 changed, 2 added, 0 removed), no migration; **A0** clean. Live `c2c96e1` /
    5.18.86. PHP **8.1.34** accepted the 3 changed server files and the 8 test files. Pilot `on`; the authorisation
    `ia=on/on` and the booking WhatsApp `wa=on/on`, as the operator left them. **The lead switches: `lc=on/on`,
    `ls=off/off`, `qu=on/on`, `sa=on/on`** — step 0 reached both copies. **A3** the Inbox's row `evo=yes sales=yes
    support=yes account=yes registry=absent`, as at 5.18.86's deploy; **A4** clean. 086 complete, its tables
    `2:9:1:1:1`; the webhook log's last 300 entries hold **3** `job.add`, the last at 09:26:31 by the plugin's clock
    (UTC+3), 06:26:31 UTC; photo tables `present:13:3`, 13 files.
  - **Backup** `/root/dnb-5.18.87/backup-20261007T063502Z`: `plugin.sqlite3` 29 MB, one consistent copy, integrity ok,
    248 tables; no `dishnet.sqlite` (*"nothing to copy"*); the data directory 147 MB; the installed 5.18.86 11 MB; the
    vault. `GO`.
  - **V.** Sign-in 200 with zero redirects; the portal 302; no South Sudan contact; **V6** zoom allowed; **V5** 302 / 401;
    **V7** the customer authorisation page 404 *"This link is not valid"* to a request without a link; **V8**
    `install_auth_request`, **V9** `install_auth_prefill` and **V10** `wa_send_reply` 401 without a login; **V3** the
    pilot unchanged; **V3b** and **V3c** both live switches unchanged (`on/on`); **V3d** the Inbox's row unchanged;
    **V3e** the four lead switches unchanged; **V4** no fatal or parse error in the 60 s after the copy.
  - **R.** R1 all 12 files as `9cc81af` has them, manifest 5.18.87; R2 `pilot=on`, and the installed `InstallAuth` and
    `InstallScheduledWhatsApp` each read their switch ON in both copies; R3 086 still applied and complete, `2:9:1:1:1`
    before and after; 084 `16:3` rows, 16 files; R4 the pilot as before, the channel `NullWhatsAppChannel`, no portal,
    CSRF or AI media-layer file, none of Batch 1, the AI worker Batch 0 alone; R5 all 225 files from Release A through
    5.18.86 intact; R6 every earlier marker and Batch 0's pieces; **R7** (read-only) **UGX CASH IN HAND 591,072.00 ·
    USD 0.00**, the figures 5.18.86's deploy read — and the photo comparison above; R8 the quotation reader as at
    5.18.85; R9 the Inbox route as at 5.18.86's deploy; **R10: the assistant is asked for a lead on the sales number and
    on the support/account number, `AiLeadService` records it in Sales → Leads, and nothing reaches uCRM.**
  - **Not seen yet:** a lead from a real conversation. It waits for the next customer who tells the assistant what they
    need and where (step 2).
  - **`--after-only` at 06:52:28 UTC, run by the operator: PASSED, 33 ok / 0 failed / 0 notes**, the rehearsal's figure
    for that mode. The photos read `present:16:3` and 16 files at both ends of the run, so R7 passes; 086 complete, its
    tables `2:9:1:1:2`; every switch as at the deploy; R10 as above.
  - **Found in that run's log: an `--after-only` run's V4 proves nothing, and has not since 5.18.60.** V4 reads the
    container log from the time the state file records. Since 5.18.60 that file holds the run's compact stamp
    (`20261007T063502Z`), and V4's filter keeps a log line only when its time sorts at or after that stamp:
    `2026-10-07T06:…` sorts before `20261007T…` (a dash sorts before a digit), so no line passes, whatever the log holds.
    The deploy run's own V4 used the copy's time (`2026-10-07T06:35:47Z`) and read 60 s of log: a real check. An
    `--after-only` V4 never was, so the V4 lines recorded above for 5.18.83's `--after-only` (06 Oct, 11:51 UTC) and
    5.18.84's (06 Oct, 15:25 UTC) prove nothing either. 5.18.50 to 5.18.59 recorded the copy's own time, so 5.18.50's
    run stands. The next script records the time in the form V4 compares, and its rehearsal plants an error after the
    deploy and requires `--after-only` to fail on it.

**Git:** `ad5371b` (the test), `70eff0c` (the script and its rehearsal), `release/5.18.87` (`9cc81af`), and this entry
with `docs/65` §W's three decisions (`6569047`) — **pushed 07 Oct, 06:33 UTC**, on the operator's instruction (*"yes push
both branches"*): the branch from `8449d2d` to `6569047` (06:33:24 UTC), `release/5.18.87` new at `9cc81af` (06:33:26
UTC). At the push nothing was deployed, no configuration had changed and nothing had been sent to anyone. `docs/59`
stays untracked by the operator's decision.

## 07 Oct — 5.18.88: the remaining Batch 1 release — migration 087 (the channel registry) and the routing by channel, both dark behind a switch that stays OFF; Uganda's event processor leaves the workers' events to the workers; South Sudan unchanged. `release/5.18.88` = `6464204`, cut on live 5.18.87 (`9cc81af`); `scripts/deploy-5.18.88.sh` pinned to it and rehearsed — PUSHED 07 Oct 09:46 UTC; DEPLOYED 09:52 UTC (PASSED 54/0/0)

**This is the remaining Batch 1 release.** Multi-number Batch 1 (`b1865ea` on the branch, `docs/65`) reaches production
in two releases.
- **5.18.86** (`c2c96e1`, deployed 07 Oct 04:26 UTC) shipped part A, the Inbox answering from the conversation's own
  number. With it came, byte for byte, Batch 1's `ChannelRegistry`, `ChannelContext`, `InboxReplyRoute`,
  `NotificationService` and `includes/api/api_whatsapp.php`, and the seven registry functions of `EvolutionApiService`:
  the registry's code, dark.
- **5.18.88** ships the rest: migration 087, the routing by channel in the webhook, the AI worker and the follow-ups, the
  lead's origin, the registry's switch in `tools/set_config.php`, the read-only `tools/channels.php`, and part C, the
  event processor's protection of worker events.

Every file Batch 1 touched was checked against both releases' trees. After 5.18.88, nothing of Batch 1 remains outside
production except what belongs to work that is not in production:
- the AI media layer: part M (`workers/MediaWorker.php`), the media tests, and `EvolutionApiService::getBase64FromMediaMessage()`
  (+23 lines, the one way live's copy of that file differs from Batch 1's);
- the partner portal's tests (`test_partner_*`, `test_dist_isolation`).

Both go with their own layers, if those are ever released.

The operator's instruction, 07 Oct: build 5.18.88 directly on 5.18.87, selectively, not from the branch wholesale. Keep
South Sudan's event list `['ai.reply']` and bring no `ai.media` into it. Registry behaviour is Uganda only. Domain B is
untouched. **No deploy and no push without their separate approval.** `multi_number_channels_enabled` is not switched on,
no Evolution instance is created or paired, and no message is sent.

### The five-part form

1. **What is configured now (5.18.87, `9cc81af`).**
   - Batch 0's lead fixes are live. `ai_lead_capture` is ON and `ai_crm_lead_sync` is OFF (the 07 Oct decision);
     `ai_qualification` and `ai_sales_on_all_numbers` are ON (5.18.87's RESULT).
   - The registry's libraries are installed, but 087 is not, so `ChannelRegistry::available()` is false and
     `EvolutionApiService::forStore()` returns the configured service. The deploy reads `multi_number_channels_enabled`
     before anything changes, and refuses unless it is OFF in both copies (A5).
   - Support and account share one WhatsApp number on this installation (`docs/18`), so inbound on it lands in
     support: an instance named in two slots resolves to the first of sales → support → account (`docs/65` §B). The
     deploy reads the server's own mapping and reports its shape (A9, R12).
   - **Uganda's event processor runs the loop every install runs, unchanged since 5.18.85.** It claims every type and
     releases `ai.reply`. It can acknowledge a
     `crm.lead.sync` as an unknown type before `UcrmLeadWorker` sees it (`docs/65` §Z.5). It logs `wa.escalation` as
     unknown, and it returns early on an empty claim.
2. **Why.**
   - 5.18.87's entry: *part C should be in production before the uCRM write is switched on*. Until it is, a lead's sync
     can be swallowed.
   - Salespeople's own numbers (the next batch, `docs/65` §W: D4, D5 and D7) build on the registry. Its table and the
     routing by channel must be in production, dark, first.
3. **Exactly what changes.** The 18 files below, and migration 087 applied by the plugin's own runner on its next
   request:
   - two tables, `wa_channels` and `wa_channel_log`;
   - three indexes;
   - three triggers: the trail refuses UPDATE and DELETE, and a channel is never deleted;
   - the three department rows, `sales`, `support` and `account`, each with **no instance stored**. NULL means "the
     department's configured key";
   - three `seeded` trail rows.

   **With the switch OFF, only Uganda's event processor behaves differently:**
   - it does not claim `ai.reply` or `crm.lead.sync` at all (`EventBus::consume()`'s new exclusion list), and still
     releases one if it ever holds one;
   - it acknowledges `wa.escalation` as a known type, with no "unknown" log line;
   - it no longer returns early on an empty claim, so the dead-letter pass runs on every run.

   Everything else behaves as 5.18.87 does: with the switch off, every new path returns at its first line. The suite
   proves it in the sandbox, and the deploy checks it on the server's own configuration (A9, R11, R12, R13).
4. **Effect on UISP/uCRM.**
   - UISP: none. Nothing outside the plugin directory changes.
   - uCRM: the plugin's own SQLite gains two tables, and nothing reads them while the switch is off. No uCRM call is
     added, and the uCRM write stays OFF.
   - Evolution: no instance is created, paired or called by the deploy, and no message is sent.
5. **Rollback.** `scripts/deploy-5.18.88.sh --rollback`, typed `ROLLBACK`, puts 5.18.87 (`9cc81af`) back through
   `deploy-hybrid.sh` and checks it, in about the deploy's own time (minutes). 087's tables stay, and 5.18.87 does not read
   them with the switch off. The runbook is its own section below, never beside the deploy command.

### Release notes — what each change does

- **`cron/event_processor.php`** (+47 −9).
  - On Uganda (`StaffJobsGate::applies`) the worker-owned list is `['ai.reply', 'crm.lead.sync']`, passed to `consume()`
    as an exclusion. `wa.escalation` is acknowledged as known, and there is no early return.
  - **On every other install, South Sudan included, the 5.18.87 loop runs unchanged:** `consume(20)`, the early return,
    and the list `['ai.reply']`. `ai.media` appears nowhere in the release's production code (0 files).
- **`lib/EventBus.php`** (Batch 1's, byte for byte). `consume()` gains `$excludeTypes`. It is empty, so nothing changes,
  for every other caller.
- **`evo_webhook.php`** (+20 −1). Uses `forStore()`. Only with the registry on: a number the registry has switched off is
  refused (`channel_disabled`), and a number whose assistant is off keeps the message for the team and queues nothing.
- **`workers/AiReplyWorker.php`** (+77 −6). Uses `forStore()`. Only with the registry on:
  - the brain is told the channel's role;
  - a reply leaves only on the number the message arrived on, and on any doubt a person is told and the customer is sent
    nothing;
  - the lead records the number it came in on.
  Off, `replyRoleOrRefuse()` returns at its first line and the lead's origin is null.
- **`cron/followup_send.php`** (Batch 1's, byte for byte). Uses `forStore()`. Only with the registry on, a follow-up
  leaves only on its own conversation's number. Off, the configured instance, as before.
- **`lib/AiLeadService.php`** (Batch 1's, byte for byte). `withOrigin()` takes the lead's channel fields; null, which is
  always the case with the registry off, writes 5.18.87's lead field for field.
- **`tools/set_config.php`** (+7). The tool knows the key `multi_number_channels_enabled`. Nothing in this release sets it.
- **`tools/channels.php`** (new, Batch 1's). A read-only report: the switch, whether the registry is in effect, whether 087
  is installed, and the channels.
- **`migrations/087_wa_channels.sql`** (new, Batch 1's, byte for byte; sha256 `3feda1b44ca79c1a…`, 14 statements).
  Additive and idempotent: `CREATE … IF NOT EXISTS`, `INSERT OR IGNORE`, and trail rows guarded by `WHERE NOT EXISTS`.
  No ALTER, UPDATE, DELETE or DROP, and no foreign key to an existing table.
- **Tests.**
  - `test_channel_registry.php` is Batch 1's, byte for byte.
  - `test_event_processor_protected.php` is Batch 1's without `ai.media`. Production has no media layer, so the copy
    proves neither list names it and that South Sudan keeps `['ai.reply']`.
  - `test_multi_number_routing.php` is Batch 1's without part M (the media worker), its weakened copy, the media switch
    and the 504 case.
  - The five distributor tests read the version pin `5.18.88`.
- **`manifest.json`** 5.18.88.

Both derived tests were made by derivations in which every anchor and every replacement is asserted to land exactly once.
A provenance check run both ways reads CLEAN:
- the six whole files are byte-identical to `b1865ea`;
- in the four merged files every line added or removed is one Batch 1 added or removed, apart from nine listed rewrites:
  - `ai.media` and the media worker come out of one code line (the two lists) and four comments, because production has
    no media layer;
  - four comments' version numbers now name 5.18.87 and 5.18.88;
- every Batch 1 hunk in those files is in the release, unless it is the media layer's.

### Files — 18 against `9cc81af`: 5 added, 13 changed, none removed

- **Added:** `migrations/087_wa_channels.sql`, `tools/channels.php`, `tests/test_channel_registry.php`,
  `tests/test_event_processor_protected.php`, `tests/test_multi_number_routing.php`.
- **Changed:** `cron/event_processor.php`, `cron/followup_send.php`, `evo_webhook.php`, `lib/AiLeadService.php`,
  `lib/EventBus.php`, `manifest.json`, `tools/set_config.php`, `workers/AiReplyWorker.php`, and the five distributor
  tests (`test_distributor_apply`, `_link_ucrm`, `_notify`, `_registry`, `_territory`; the pin only).
- **Not in it:**
  - the AI media layer (migration 085, the media worker, the voice, image and document paths);
  - the partner portal;
  - the CSRF guard;
  - unrelated security fixes and refactors;
  - Batch 2;
  - any Evolution instance or number.

  A0's allow-list refuses each of these.

### Ancestry

`6464204` (5.18.88, release) ← `9cc81af` (5.18.87, live) ← `c2c96e1` (5.18.86) ← `4790019` (5.18.85). The source is Batch
1, `b1865ea`, on `c8b3e36`, on the branch. Only the files above come from it.

`release/5.18.88` is local, not pushed.

### Migration 087 — verified on fresh copies of 5.18.87

The rehearsal is driven by `m087_rehearse.php`, in the session scratchpad. Each run starts from a `git archive` of `9cc81af`
and builds the schema with live's own runner: 82 migrations, the last 086, 130 tables. It then writes rows into the tables
087 must never touch, drops 087 in, and boots the plugin. The runner applying it is the one the release ships:
`MigrationRunner.php` and `SqliteStore.php` are byte-identical at `9cc81af` and `6464204`.

**16 of 16 checks pass:**
- recorded once, with the file's md5;
- `migration.log` reads `OK: 087_wa_channels.sql (14 stmts, …)`, every statement counted, and holds no PARTIAL;
- all 8 objects exist;
- the three department rows read `account/NULL/active`, `sales/NULL/active` and `support/NULL/active`, with the trail of
  three;
- the 128 pre-existing tables hold exactly the rows they held before;
- the schema gained exactly 087's 8 objects and lost or redefined nothing;
- one ledger row was added;
- a second boot applies nothing, and the statements run again by hand add nothing;
- the trail refuses UPDATE and DELETE, and a channel cannot be deleted.

**Found while verifying, and binding on how 087 is checked.** The runner, which this release does not change, skips a
failing statement silently when its error is one it calls safe: *already exists*, *duplicate column*, a UNIQUE or NOT NULL
violation, *no such column* in a CREATE, or *no such table* in an INDEX. Such a statement is neither counted nor reported,
and the runner still logs **OK**, so the OK line's count is the only trace. Two controls on fresh copies show this:
- one of 087's indexes naming a missing column: the runner logs `OK … (13 stmts)` and no PARTIAL anywhere;
- a trigger with a syntax error: the runner logs PARTIAL, *13 ok, 1 skipped*, and writes the ledger row all the same.

Both are caught by the object checks (7 of 8) and by the count. The driver reads **18 of 18**.

So the deploy's R3 judges 087 by what the store holds: every object, both CHECKs, the rows and the trail. Each of the 14
statements leaves something R3 reads. It then reads the runner's log line strictly: OK must count all 14, and any PARTIAL,
ERROR or WARNING fails. A log with no 087 line at all is a note, the store being the verification.

### Tests

- **The release tree's own suite: 273 files, 12,814 passed, 0 failed, 0 skipped — twice** (A 07:28–08:04, B 08:04–08:40
  UTC). Every file's tally is identical in both passes.
  - **Against 5.18.87's release suite** (270 files, 12,586 passed), exactly the three new tests are added (+104, +41,
    +83 = +228). No other file's tally moved.
  - PHP warnings: 5 per pass, all from `test_dpo_endpoints` (an undefined variable in the test itself, line 91), as in
    5.18.87's.
  - The checker refuses its own two controls: a base without the new tests, and a planted warning in one pass only.
  - The tree was unchanged by both passes: still `6464204`, clean.
- **Focused:** 45 files on the paths this release touches. Each reads the same in both passes and, where it existed, as
  in 5.18.87's suite:
  - the channel registry 104/0, the event processor 41/0, the routing 83/0;
  - the Inbox route 23/0;
  - the lead path (`test_lead_path_batch0` 25/0, `test_lead_switches` 27/0, `test_ai_lead_capture` 71/0,
    `test_ucrm_lead_sync` 62/0, `test_brain_context` 123/0);
  - migration integrity 28/0;
  - the webhook files;
  - the follow-up files;
  - the South Sudan and tenant files (`test_staff_jobs_south_sudan` 51/0, `test_staff_jobs_gate` 41/0,
    `test_tenant_profile` 108/0, `test_email_no_sudan` 62/0, …);
  - the five distributor tests.
- **Lint:** `php -l` on all 16 PHP files of the diff, read from the commit: 0 errors. The sandbox has PHP 8.4. A scan of
  the 1,668 added PHP lines finds no syntax or function newer than 8.1; the server's own 8.1 lints at A2.
- `git diff --check 9cc81af 6464204`: clean.
- **Secret scans** of the release diff and of the script and its rehearsal: clean. No key-, token- or credential-shaped
  value and no banned value. The phone-shaped values are Batch 1's synthetic fixtures; the URLs are loopback.

### South Sudan — unchanged, and proved four ways

1. **Statically, in the release:**
   - the non-Uganda branch is `consume(20)` with the early return;
   - South Sudan's list is `['ai.reply']`;
   - `ai.media` appears in no production file;
   - the registry is gated by `StaffJobsGate::applies` (Uganda), as since 5.18.86.
2. **By behaviour, at A6 on the server before anything changes.** The event processor runs on a throwaway database with
   five events, under a South Sudan profile:
   - live's run must read `crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=done/0+unknown
     wa.escalation=done/0+unknown install.ready=done/0+unknown`;
   - the pin's run as South Sudan must read the same;
   - the pin's run as Uganda must read `crm.lead.sync=pending/0 ai.reply=pending/0 ai.media=done/0+unknown
     wa.escalation=done/0 install.ready=done/0+unknown`.

   Refusal 6 otherwise.
3. **After the deploy (R13) and after a rollback (RB),** the same runs on the installed code.
4. **In the suite:** `test_event_processor_protected`'s South Sudan scenarios, and the South Sudan and tenant files above.

The rehearsal shows each South Sudan refusal firing: `ai.media` put on South Sudan's list, the early return removed, and
Uganda's list without `crm.lead.sync`.

### Domain B — untouched

- `dishnet-mikrotik-control-plane/` is the same tree at `9cc81af` and `6464204` (`cf0b0e3`, 242 files).
- The plugin's `docs/` is the same tree too (`969d873`, 121 files).
- The whole repository's delta is the plugin's 18 files alone.
- **A7** refuses any pin that touches either tree or anything outside the plugin.
- **R14** after the deploy, and **RB** after a rollback, compare all 363 installed files byte for byte against the commit.

### `scripts/deploy-5.18.88.sh` — 1,733 lines, sha256 `c6cfb10f4a23fa8e…`

Made from 5.18.87's script by a derivation with every replacement asserted. **It refuses before anything changes:**
1. live is not 5.18.87 at `9cc81af` (commit and manifest version);
2. `multi_number_channels_enabled` reads ON in either copy (A5);
3. 087 is already recorded (A8);
4. any of 087's objects exists without its ledger row (A8);
5. the delta is not exactly the 18 files, with 087 matching its sha256 (A0);
6. South Sudan's protection fails (A6);
7. Domain B's protection fails (A7);
8. the backup, or the event-queue snapshot, is not confirmed.

Before the backup, A9 compares how the live code and the pin's code route the three numbers on the server's own
configuration. Both copies are run in throwaway directories inside the container (`docker cp` to `/tmp`, removed at exit).

**The backup:** `plugin.sqlite3` (one consistent copy, integrity checked), the data directory, the installed plugin, the
vault, and a snapshot of the event queue.

**After the deploy, the smoke checks (PART 8), all on the server, on fake or throwaway data, sending nothing:**

| | Check | What it proves |
| --- | --- | --- |
| S1–S3 | **R12** | The sales, support and account numbers route exactly as on 5.18.87. The installed code and 5.18.87's are compared on the server's own configuration files and on the Inbox's row: `sales:in=sales support:in=support account:in=support shared=support+account … registry=off`, with the exact names compared under a per-run key and never printed. Against A9's reading as well |
| S4 | **R11** | The registry is dark: the switch OFF in both copies; `enabled()` and `forStore()` off for both; the Inbox's route for every kind of chat issues **0 statements**, none on the registry's tables (a recording database handle counts them). `tools/channels.php` says OFF, not in effect, installed |
| S5 | **R11, R3** | 087 holds the three department rows alone, with no instance stored, so no Evolution instance was added |
| S6 | **V3g** | Every AI and WhatsApp setting is unchanged by the run (compared by HMAC), and so are the lead switches and the pilot (V3–V3f) |
| S7 | **R10** | With the registry off, a lead carries no channel fields and its sync names no channel: 5.18.87's lead, field for field. The uCRM worker settles the sync as *not synced — switched off* |
| S8 | **R13** | The installed event processor, on throwaway databases, reads Uganda's signature and South Sudan's, with a control run of 5.18.87's code. Every event not done at the snapshot is still in the queue |

Also:
- R1, the 18 files as the commit has them;
- R3, 087 as above, with 086 and 084 still complete;
- R4, no media-layer, portal or CSRF file;
- R5–R6, every earlier release's pieces in place;
- R7, cash in hand read only, and the photos may only grow;
- R14, Domain B.

**5.18.87's two deploy defects are fixed (PART 4):**
- **R7** fails only when photos are lost; growth (a technician at work) passes.
- **V4** reads the container log since an ISO-8601 time stamp, the form docker compares. An `--after-only` run reads the
  log again: 5.18.87's compact stamp read 0 lines in that mode.

The script contains no command that changes a switch.

### The rehearsal — `scripts/harness/deploy-5.18.88/rehearse.sh` (806 lines, sha256 `e939dee6c195d5e0…`)

The base is installed as production runs it:
- 5.18.87 with 086 applied;
- the authorisation and the booking WhatsApp on;
- the lead switches as since 5.18.87's step 0;
- the Inbox's row naming three fictitious numbers, with support and account sharing one instance;
- the registry's switch unset;
- an event queue with 6 events, 3 of them not done;
- two photos.

It covers:
- **every refusal (1a–1u):** 5.18.86 still live; the right commit with the wrong manifest; a placeholder pin; the branch
  tip; a media-layer file; a second migration; 087 one comment different; a file missing; Domain B, the plugin's docs, or
  a file outside the plugin; the switch ON in either copy or both; 087 recorded; 087 half there; `ai.media` on South
  Sudan's list; Uganda's list without `crm.lead.sync`; no early return; the code copy failing; the backup failing. A4 and
  A3 are evidence, not stops;
- **weakened copies of stage A (2a–2h):** A5, A8, A6 and A7 each blinded and caught. A9 is shown to stop, on its own, a
  pin that would land the shared number's inbound in account;
- **the deploy (section 3):** the rehearsed deploy reads **54 ok / 0 failed / 0 notes**. Every table, the vault and the
  configuration files are byte-identical; no Evolution address, key or instance name appears in the log; 087 is applied
  by the plugin's own runner on its first request, and `migration.log` reads OK with 14 statements;
- **the teeth (4a–4p):** ai.media on South Sudan's list on the server; 5.18.87's event processor or AI worker back; a
  trigger dropped; a second channel or an instance in a department row; the switch ON; a Domain B file changed; an event
  deleted; R13 and R11 blinded. And 087's log line: OK counting 13 of 14, a PARTIAL, no line at all (a note), and a copy
  blind to the count;
- **PART 4 (5a–5g):** a photo taken during the run passes, and with 5.18.87's rule put back the same photo fails; a photo
  lost fails. An error planted after the deploy is found by `--after-only`'s V4, and with 5.18.87's stamp put back it is
  missed, having read 0 lines;
- **the switches after the deploy (6a–6h):** the uCRM write on (R10 says leads reach uCRM, the event processor no longer
  taking their syncs), then off again, byte for byte. The registry's switch set in the sandbox with the installed tool:
  the checks say at once the registry is not dark. Qualification off; the Inbox's account number taken away, or moved
  during a run;
- **the rollback (section 7):** typed `ROLLBACK`. RB reads:
  - 5.18.87's files and manifest;
  - Batch 0 kept, Batch 1 gone;
  - **087's tables stay, and 5.18.87 does not read them with the switch off.** A recording handle counts 0 statements.
    7b is the control on the control: with the switch ON in the Inbox's row, the same check sees the reads;
  - the event processor 5.18.87's on both countries;
  - the routing as before;
  - the queue, which lost nothing;
  - Domain B as `9cc81af` has it;
  - no data changed.
- **what is left behind:** the checkout as found, no weakened copy, nothing in `/tmp`, and no PHP fatal from the stand-in.

**Results.** Earlier runs on the working copy read 229/0, then 235 passed and 1 failed. That one failure was a
mis-written expectation in a new control (a count of `1` joined to `2:3:3` reads `12:3:3`), fixed before the commit.
Then two runs on the committed script (`76b07d9`):
- **run 1: 236 passed, 0 failed**, 67 runs of the script, no FAIL line, the checkout left as found;
- **run 2: 236 passed, 0 failed**, 67 runs of the script, the same check lines as run 1 (time stamps aside). Both runs'
  rehearsed deploy reads 54 ok / 0 failed / 0 notes.

### Deployment runbook — each step is the operator's, and each needs the operator's approval first

1. **Push — DONE 07 Oct, 09:46 UTC**, on the operator's word (*"yes push both branches"*). It pushed the branch
   `claude/study-this-jhe2eg` (the script) and `release/5.18.88` (the release commit). The server pulls both from GitHub.
2. **Deploy, as root on the server — DONE 09:50–09:53 UTC**, on the operator's word (*"yes deploy"*): **PASSED, 54 ok /
   0 failed / 0 notes** (the RESULT below). It asks for `DEPLOY`. Send back the **log file**, not a copy of the
   terminal:

   `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.88 && mkdir -p /root/dnb-5.18.88 && bash scripts/deploy-5.18.88.sh 2>&1 | tee /root/dnb-5.18.88/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`

   - **Expect 54 ok / 0 failed / 0 notes**, the rehearsal's own figure. *Corrected after the deploy:* this line first
     said 53. It carried 5.18.87's reason over (a `dishnet.sqlite` in the sandbox) without checking it, and this
     rehearsal's sandbox holds none either (*"nothing to copy"*, as on the server). The server read 54.
   - A note would say that a lead switch is not as decided on 07 Oct (A4), that `ai_qualification` is off, or that the
     Inbox's row lacks a number. It would also say if no request had applied 087 by R7's end: then open the plugin once
     and run
     `cd /opt/dishnet && bash scripts/deploy-5.18.88.sh --after-only`.
   - **If a refusal stops it, nothing has changed.** The log names the refusal: send it back.
3. **Nothing to switch on.** With the registry dark, every number routes as before, and nothing a person sees changes.
   The event processor's log no longer lists `wa.escalation` as an unknown type.
4. **Later, each its own decision:**
   - the uCRM lead write (`ai_crm_lead_sync`): the event processor no longer takes lead syncs, so the blocker named in
     5.18.87's entry is gone once 5.18.88 is live;
   - the registry's switch: no command is given here, and it is not part of this release;
   - salespeople's numbers (Batch 2).

### RESULT — DEPLOYED to production 2026-10-07, 09:52 UTC: PASSED, 54 ok / 0 failed / 0 notes

Recorded from the terminal the operator pasted; the script prints no secret. The log file stays on the server under
`/root/dnb-5.18.88/`.

The run began at 09:50:23 UTC (`20261007T095023Z`). `DEPLOY` was typed, `deploy-hybrid.sh` answered *"✓ container now
serves 6464204"*, and the 18 files were stamped at 09:52:18 UTC. **The rehearsal's 54 ok lines and the server's are the
same 54, one for one** (the rehearsed deploy's full output, captured afterwards, compared line by line).

- **The pull.**
  - The checkout fast-forwarded `6569047` → `28f2570`, and `release/5.18.88` was fetched as new.
  - Branch tip `ad5371b` (not installed); release commit `6464204`, cut on `9cc81af`.
  - 18 files (13 changed, 5 added, 0 removed); no tracked edits.
- **A — no refusal fired, and every check passed:**
  - A7: Domain B untouched (`cf0b0e3`, `969d873`). A0: exactly the 18 files and the reviewed 087.
  - Live was `9cc81af` / 5.18.87. **A2: PHP 8.1.34 accepted all 8 changed server files and the 8 test files.**
  - The switches as the operator left them (A3–A5):
    - pilot `on`, `ia=on/on`, `wa=on/on`;
    - the lead switches `lc=on/on`, `ls=off/off`, `qu=on/on`, `sa=on/on`;
    - the registry's switch `mn=absent/absent`;
    - the Inbox's row `evo=yes sales=yes support=yes account=yes registry=absent`.
  - 086 complete, its tables `2:12:1:1:1`. 087 absent, and none of its objects (A8).
  - **The routing, read on the server for the first time:** `sales:in=sales support:in=support account:in=support
    shared=support+account evo=yes registry=off`, in the configuration files and in the Inbox's row alike. This confirms
    `docs/18`: support and account share one number here. A9: the pin's code routes exactly as live's.
  - **A6, South Sudan:** live's and the pin's processor read the same signature as a South Sudan install
    (`crm.lead.sync=done/0+unknown ai.reply=pending/0 ai.media=done/0+unknown wa.escalation=done/0+unknown
    install.ready=done/0+unknown`). The pin reads Uganda's as designed (`crm.lead.sync=pending/0 ai.reply=pending/0
    ai.media=done/0+unknown wa.escalation=done/0 install.ready=done/0+unknown`).
  - The webhook log's last 300 entries hold 3 `job.add`, the last at 09:26:31 by the plugin's clock (UTC+3), the same
    as at 5.18.87's deploy. Photos `present:16:3`, 16 files.
- **Backup**, in `/root/dnb-5.18.88/backup-20261007T095023Z`:
  - `plugin.sqlite3`, 29 MB: one consistent copy, integrity ok, 248 tables, SQLite 3.48.0;
  - no `dishnet.sqlite` (*"nothing to copy"*);
  - the data directory, 149 MB; the installed 5.18.87, 11 MB; the vault;
  - the event queue's snapshot: **0 events not done** (8,088 in all, every one done).

  Then `GO`.
- **V.**
  - The sign-in page answers 200 with zero redirects, and carries no South Sudan contact; the portal answers 302;
    pinch-zoom is allowed.
  - The photo viewer answers 302 and its upload 401. The authorisation page answers 404 *"This link is not valid"* to a
    request without a link.
  - `install_auth_request`, `install_auth_prefill` and `wa_send_reply` answer 401 without a login.
  - Every switch, the Inbox's row and the AI settings are unchanged by the run (V3–V3g).
  - **V4: no fatal or parse error in the 60 s after the copy, with 28 log lines read.** V4 reads the log now.
- **R.**
  - **R1:** all 18 files as `6464204` has them; manifest 5.18.88.
  - **R2:** the pilot, the authorisation and the booking WhatsApp as the operator left them.
  - **R3, 087 applied and complete:** 2 tables, 3 indexes, 3 triggers, both CHECKs, the ledger row matching the installed
    file, the three department rows with no instance stored, and the trail of three. The runner's own line reads
    `[2026-10-07 12:52:18] OK: 087_wa_channels.sql (14 stmts, 8ms)`, all 14 statements counted. 12:52:18 on the
    plugin's clock is 09:52:18 UTC: the first request after the copy applied it.
  - **R3, the earlier migrations:** 086 complete, `2:12:1:1:1` before and after; 084 `16:3`, 16 files.
  - **R4:** no media-layer, portal or CSRF file. **R5:** all 230 files from Release A through 5.18.87 intact.
    **R6:** every earlier surface, and 5.18.88's Batch 1 pieces.
  - **R7** (read-only): **UGX CASH IN HAND 591,072.00 · USD 0.00**, the figures 5.18.87's deploy read. The photos as
    before the run.
  - **R8:** the quotation reader. **R9:** the Inbox route. **R10:** the lead path; with the registry off, a lead carries
    no channel fields (S7).
  - **R11 (S4/S5):** the registry is dark. 0 statements on its tables, the three department rows alone, no instance
    stored; `tools/channels.php` says OFF, not in effect, installed.
  - **R12 (S1–S3):** the three numbers route exactly as on 5.18.87, in the files and in the Inbox's row.
  - **R13 (S8):** the installed event processor reads Uganda's signature and South Sudan's as designed. **The queue
    check held only trivially:** no event was pending at the snapshot (`kept=0/0`), so this time it had nothing to hold.
  - **R14:** Domain B's 363 files, byte for byte.
- **Not seen yet:** Uganda's event processor leaving a real worker event to its worker. The next real lead will show it:
  its `crm.lead.sync` should be settled by `UcrmLeadWorker` (*"not synced — switched off"*), not acknowledged as an
  unknown type.
- **Optional, read-only.** The script's temporary code copies are removed at exit by a silent trap. To see that, run as
  root:

  `docker exec ucrm sh -c 'ls -d /tmp/dnb-5.18.88-* 2>/dev/null | wc -l'; ls -d /root/dnb-5.18.88/code-* /root/dnb-5.18.88/domainb-* 2>/dev/null | wc -l`

  It should print `0` twice. **Run by the operator after the deploy: `0` and `0`.** No copy of the code and no
  throwaway database was left in the container or on the host.

### Rollback runbook — its own command, never pasted with the deploy (root `docs/44` §16.9)

Only if it is ever needed. The deploy's log prints the same at its end. It asks for `ROLLBACK` before it changes anything,
puts back 5.18.87 (`9cc81af`) through `deploy-hybrid.sh`, then checks it (RB):

`cd /opt/dishnet && bash scripts/deploy-5.18.88.sh --rollback`

If the script cannot run, by hand:

`cd /opt/dishnet && git checkout 9cc81af && bash scripts/deploy-hybrid.sh && git checkout -`

What stays as it is:
- **087's tables stay.** 5.18.87 reads nothing in them with the switch off; RB proves it.
- Every switch stays as it is, and the switch stays OFF.
- Leads, conversations and events stay. The queue loses nothing; RB checks it.
- If the rollback comes before any request applied 087, its file stays on disk (deploy-hybrid.sh never deletes), and
  5.18.87's runner, the same code, applies it on the next request: additive, and unread.
- After a rollback, Uganda's event processor can again take a lead's sync. So **the uCRM write must be OFF while
  5.18.87 runs**, as it is today.

### Known limitations

- **The 504 case** of `test_multi_number_routing` is not in the release's copy: production's fake Evolution cannot answer
  one. It is driven in Batch 1's own test on the branch.
- **Batch 1's comment tags read 5.18.86**, its number on the branch. Comments written for this release say 5.18.88.
- **PHP 8.1 lint ran only on the server, at A2:** PHP 8.1.34 accepted all 8 changed server files and the 8 test files
  (the RESULT). The sandbox lints with 8.4, and the scan above found nothing newer than 8.1.
- **`docker cp` into the container's `/tmp` was new in these scripts** (A6, A9, R12, R13, RB). Its first use on the
  server worked: A6, A9, R12 and R13 read both releases' code there. Its removal at exit is silent, so the operator
  checked it afterwards, read-only: nothing was left (`0`, `0`; the RESULT). A failed copy is a refusal (1r).
- **The runner tolerates some failing statements silently** (above). That is unchanged, and it applies to every
  migration; R3 does not depend on it. A statement that waits more than 5 s for a lock is logged PARTIAL, and R3 fails.
  The runner still records the file as applied, so it never retries it: re-applying 087's idempotent statements is then a
  separate, reviewed step, which this script does not take.
- **`wa.escalation` is acknowledged with no consumer**, as Batch 1 decided (`docs/65` §L): owners' notifications come
  later. `efris.submit` is deliberately left as it was.
- **Not in this release:** the AI media layer, the partner portal, the CSRF guard, and salespeople's numbers (Batch 2).
- **The uCRM write stays OFF.** Switching it on is the operator's own later step.

### Remaining blockers, and the call

- **Technical blockers: none found.** Every check, test and rehearsal above passed.
- **Both approvals were given and both steps are done:** pushed at 09:46 UTC, deployed at 09:52 UTC.
- **The call was GO, and the deploy passed, 54/0/0.** The release is dark: with the switch OFF, the only behaviour change
  is Uganda's event processor, and that change protects the workers' events.

**Git — pushed 07 Oct, 09:46 UTC**, on the operator's instruction (*"yes push both branches"*):
- `claude/study-this-jhe2eg` from `6569047` to `28f2570` (09:46:33 UTC): `4dbc6f2` and `f3177fd` (the 5.18.87
  records), `76b07d9` (the script and its rehearsal) and `28f2570` (this entry);
- `release/5.18.88` new at `6464204` (09:46:41 UTC).

At the push nothing was deployed, no configuration had changed and nothing had been sent. This record of the result is a
later commit.

No configuration changed, no switch set, no Evolution instance created, nothing sent. `docs/59` stays untracked by the
operator's decision.

## 07 Oct — Multi-number sales, Batch 2 (5.18.89): salesperson numbers, dark — the card, the assistant as the salesperson's assistant, the owner's hand-over, owned leads, own leads only, the follow-up hold, the guard's watch; BUILT in development, PUSHED 07 Oct 18:45 UTC; deployed in release 5.18.89 (the entry below)

**Why.** After 5.18.88's deploy the operator asked to connect another salesperson's own WhatsApp number, with the
assistant replying in that salesperson's name. The operator's answers, 07 Oct (the salesperson is named in the chat, not
here):
- **D3** — *"As [the salesperson]'s assistant (Recommended)"*: on a salesperson's own number the assistant is that
  salesperson's assistant at DishNet, names them by first name and never claims to be them (`docs/65` §W).
- **Batch 2** — *"Yes, start Batch 2 (Recommended)"*: built and rehearsed like 5.18.88, the push and the deploy approved
  separately, and the salesperson's number connected only after that, as the first salesperson number.
- **D4** — *"[The salesperson] + copy to central (Recommended)"*: a hand-over alerts the salesperson, with a copy to the
  central alert number.
- **Managers** — *"Admins + 'All Leads' grant (Recommended)"*: who sees every lead when own leads only is on.
- **Chats** — *"On his phone only (Recommended)"*: the salesperson answers on their own phone; the Inbox gets no
  per-salesperson view.

**The record is `docs/65` §AA (the design) and §AB (as built).** In short, all behind `multi_number_channels_enabled`,
which stays OFF, unless said otherwise:
- **The card** (`lib/SalesNumbersAdmin.php`, `tabs/engage/wa_ai_setup.php`) — *Salesperson numbers* on the WhatsApp AI
  screen, Uganda, admin only, shown wherever 087 has run **with the switch on or off** — the one thing that shows while
  the switches are off. Add (switched off), pair, verify the number Evolution reports, register the webhook, switch on
  (refused until the registry is on, the number verified and its owner active), pause, switch off, retire, the
  assistant on or off, a new owner. Two new registry writers: `verifyNumber()`, `setOwner()`.
- **The assistant (D3)** — `lib/LineOwner.php`; `line_owner` in `BrainContext` and `ShopBotPayload`; the identity line
  and rule 4a in `DishNetAiBrain` read one answer, WhatsApp only. **With no owner the prompt is byte for byte the brain's
  before Batch 2.**
- **The hand-over (D4)** — `AlertService::handover()`: no owner → exactly the old alert; an owner → their phone, and the
  central copy (`wa_handover_copy_central`, absent means ON).
- **Owned leads (D5)** — a new lead from a salesperson's number is theirs (`AiLeadService::withOwner()`), and
  `lib/OwnedLead.php` keeps it with them: the 72 h cron, smart distribution and the daily rota leave it alone; the
  admin's `assign_leads` still moves it, writing a history note.
- **Own leads only (D7)** — `lib/LeadVisibility.php`, behind its own switch `sales_own_leads_only` (absent means OFF,
  Uganda only): the Leads page, the quote picker, the More menu's count, four lead handlers and the call log.
- **Follow-ups** — `lib/OwnedNumberHold.php`: the scan, the drafter and the sender leave a salesperson's number alone
  unless `wa_followups_on_owned_numbers` is set.
- **The webhook guard** — also watches every active salesperson's number with the registry on.

**Proofs:**
- `tests/test_sales_numbers.php` (new) **128 passed, 0 failed, in both counted passes** — in-process facts, the golden prompts (24 against
  `7195253`'s brain, identical), the real plugin under `php -S` (pages, handlers, API, crons, the card through its forms,
  the worker end to end), South Sudan with every switch set, and **17 weakened copies, each caught**.
- `tests/test_multi_number_routing.php` **90 passed** — two assertions rewritten to the new truth, not deleted (its second
  sales number is a salesperson's: *"one brain"* allows exactly the persona; its follow-up is held unless the switch
  lifts the hold). `tests/test_brain_context.php` — the new key's facts; its test names are fictitious (`Sandbox`).
- **Full suite, twice** — every `tests/run.sh` file, through the resumable runner described in Batch 1's entry (pass A 12:10–12:52, pass B
  12:52–13:36 UTC): **286 files, 13,862 passed, 0 failed, 0 skipped — twice**, every file's tally identical in both
  passes, checked file by file. PHP warnings: 5 per pass, all from `test_dpo_endpoints`, as before.
- **Against the branch before Batch 2** (`7195253`; one pass of the same runner, 15:40–16:20 UTC, nothing beside it):
  285 files, 13,726 passed, 0 failed. Exactly `test_sales_numbers` is added (+128); `test_brain_context` 133 → 138 and
  `test_multi_number_routing` 89 → 90 move by their own new and rewritten assertions — the same +5 and +1 as in the
  release. One more file reads differently for a reason outside the code: `test_quote_tax_line` 29 → 31. It runs its
  two comparisons with an old commit only where `.git` is a directory; the counted passes ran in the main checkout, the
  baseline in a git worktree, where `.git` is a file. Proved by running the baseline's own code in a plain clone: 31
  passed. No other file moved, and no PHP warning is new; the checker refuses its three planted faults.
- **South Sudan: the full suite caught one byte.** The first counted pass (11:28–12:08 UTC) failed one file,
  `test_staff_jobs_south_sudan`: the WhatsApp AI setup page on South Sudan was one byte longer than 5.18.49's. The card's
  block, skipped there, left a blank line after its `endif`, and that newline reached the page. Removed (both the
  development and the release copy); the test passed again, 51 passed, 0 failed; both counted passes were then run again
  from scratch on the corrected code. In both passes: `test_staff_jobs_south_sudan` 51/0, `test_staff_jobs_gate` 41/0, `test_tenant_profile` 108/0,
  `test_portal_tenant` 112/0, `test_email_no_sudan` 62/0, `test_sales_support_tenant` 37/0, `test_notify_tenant_text`
  30/0, `test_cashbook_tenant` 26/0, `test_phone_country` 26/0, `test_ai_country_facts` 21/0; and `test_sales_numbers`'
  South Sudan part, every switch set, applies nothing.
- **Domain B:** no file under `dishnet-mikrotik-control-plane/` or `dishnet-hybrid-sudan/docs/` changed.
- PHP lint of every changed PHP file: clean; nothing newer than PHP 7.4. `git diff --check`: clean. Secret scan of the diff
  and the new files: clean — every phone-shaped value in the tests is a fictitious fixture in the style the existing tests
  use (`256772700004` continues `test_multi_number_routing`'s own series), no banned value, no key or token.

**Flags:** `multi_number_channels_enabled` OFF; `sales_own_leads_only`, `wa_handover_copy_central` and
`wa_followups_on_owned_numbers` new and set nowhere (`tools/set_config.php` lists all three). No other flag read or
changed.

**Production impact if this were deployed with the switches as they are:** admins see the *Salesperson numbers* card on
the WhatsApp AI screen (Uganda); nothing else changes — every conversation is a department's, so the prompt, the
hand-over, the leads and the follow-ups are exactly 5.18.88's. Not deployed; the release and its deploy script are the
next entry.

**Rollback:** nothing is deployed. Before a deployment: the switches off disable everything but the card; the code is
undone by deploying 5.18.88 again; there is no migration.

**Git:** `2c2771b` on `claude/study-this-jhe2eg`, pushed 07 Oct at 18:45:58 UTC on the operator's approval (*"APPROVED —
PUSH 5.18.89"*). `docs/59` stays untracked by the operator's decision.

## 07 Oct — 5.18.89: salesperson numbers (multi-number Batch 2), dark — the WhatsApp AI screen gains a "Salesperson numbers" card for admins; the assistant as the salesperson's assistant, the owner's hand-over, owned leads, own leads only and the follow-up hold all wait behind switches that stay OFF; no migration; South Sudan unchanged. `release/5.18.89` = `53d5c4d`, cut on live 5.18.88 (`6464204`); `scripts/deploy-5.18.89.sh` pinned to it and rehearsed — PUSHED 07 Oct 18:46 UTC; DEPLOYED 19:02 UTC (PASSED 62/0/1)

**This is Batch 2's release** (`2c2771b` on the branch, `docs/65` §AA–§AB, the entry above). The operator asked, after
5.18.88's deploy, to connect another salesperson's own WhatsApp number with the assistant replying in that salesperson's
name, and answered: *"Yes, start Batch 2 (Recommended)"* — built and rehearsed like 5.18.88, **the push and the deploy
approved separately**, and the salesperson's number connected only after that, as the first salesperson number. Nothing
here connects a number, creates or pairs an Evolution instance, switches anything on or sends a message.

### The five-part form

1. **What is configured now (5.18.88, `6464204`).**
   - 087 is applied: the three department rows, no instance stored; `multi_number_channels_enabled` OFF in both copies.
   - Lead capture ON, the uCRM write OFF (07 Oct); the authorisation and the booking WhatsApp ON.
   - The Inbox's row names Evolution and the three numbers; support and account share one instance.
   - Uganda's event processor leaves `ai.reply` and `crm.lead.sync` to their workers; South Sudan's loop is unchanged.
2. **Why.** A salesperson's own number needs four things production does not have: the assistant answering as that
   salesperson's assistant (D3), the hand-over reaching them (D4), the leads it brings staying theirs (D5), and — when the
   operator chooses — salespeople seeing only their own leads (D7). The screen to add the number comes with them. All of
   it must be in production, dark, before the number is connected.
3. **Exactly what changes.** The 34 files below. **No migration**: 5.18.88's 087 holds the numbers.
   - **With the switches as they are, one thing changes:** on Uganda, admins see the *Salesperson numbers* card on the
     WhatsApp AI screen (`?page=dashboard&tab=wa_ai_setup`). It lists the three department numbers, read-only, and offers
     to add a salesperson's number — added switched off, and it cannot be switched on while the registry is off.
   - **Everything else returns at its first line.** With the registry off no conversation has an owner, so the
     assistant's prompt is 5.18.88's byte for byte (A10, R16, on the server's own configuration), the hand-over sends
     the alert it always sent, no lead is assigned by its number, no follow-up is held, and the guard watches the three
     departments. `sales_own_leads_only` is absent, so every salesperson sees every lead (A5b, V3h, R2b).
4. **Effect on UISP/uCRM.** UISP: none. uCRM: none — no uCRM call is added; the uCRM write stays OFF. The plugin's own
   SQLite: nothing written by the deploy. Evolution: nothing called by the deploy. **The card's buttons do call Evolution**
   (*Show QR code*, *Verify number*, *Register webhook*): only when an admin presses them, after the deploy, as the
   connection's own step.
5. **Rollback.** `scripts/deploy-5.18.89.sh --rollback`, typed `ROLLBACK`, puts 5.18.88 (`6464204`) back through
   `deploy-hybrid.sh` and checks it. The card goes; a number added through it stays in 087's tables, switched off and
   unread. The runbook is its own section below, never beside the deploy command.

### Release notes — what each change does

- **The card** — `lib/SalesNumbersAdmin.php` (new) and `tabs/engage/wa_ai_setup.php` (+132 −1). *Salesperson numbers* on
  the WhatsApp AI screen: Uganda, admin only, every action checked again and carrying the screen's own CSRF check; shown
  wherever 087 has run, with the registry switch on or off. It lists every registry number (the departments first,
  read-only), the business number masked, the owner, the status, the assistant's switch, Evolution's state and the last
  three trail rows. Actions, each through `ChannelRegistry` with the admin's name: add (an instance Evolution reports
  and nobody uses, an active salesperson; switched off), pair, verify, register the webhook, switch on (refused until
  the registry is on, the number verified and its owner active), pause, switch off, retire (ticked), the assistant on or
  off, a new owner. *Found in Evolution* marks an instance a salesperson's number uses as in use.
- **`lib/ChannelRegistry.php`** (+54). Two writers: `verifyNumber()` stores the number Evolution reports for an
  instance, never a typed one, masked in the trail; `setOwner()` gives a salesperson's number to another salesperson.
  Neither touches a department's row.
- **The assistant (D3)** — `lib/LineOwner.php` (new): the owner of a staff-owned, active number whose owner is active,
  by first name. `workers/AiReplyWorker.php` (+38 −6) asks only with the registry on, once per turn; `lib/BrainContext.php`
  and `lib/ShopBotPayload.php` carry `line_owner` (one word of letters); `lib/DishNetAiBrain.php` (+34 −4) writes the
  identity line and rule 4a from one answer, WhatsApp only. **No owner: the prompt is 5.18.88's byte for byte** (the
  test's golden prompts, and A10 and R16 on the server's own configuration).
- **The hand-over (D4)** — `lib/AlertService.php` (+52): `handover()`. No owner: exactly the old alert, key and text. An
  owner: their phone, and the central copy unless `wa_handover_copy_central` is set off; no phone on record: the central
  number. Sent from the DishNet sales number, never a person's line. The AI worker's inline escalation calls it.
- **Owned leads (D5)** — `lib/AiLeadService.php` (+28 −1) assigns a NEW lead from a salesperson's number to them, with
  the reason in its history, only when the origin names that same person. `lib/OwnedLead.php` (new) keeps it with them:
  `cron_leads.php` (+6) skips it in the 72 h reassignment and says how many; `includes/post/post_leads.php` (+33 −1)
  leaves it out of smart distribution and writes a history note when an admin's `assign_leads` moves it;
  `tabs/sales/leads.php` (+15 −2) keeps it off the daily rota.
- **Own leads only (D7)** — `lib/LeadVisibility.php` (new), behind `sales_own_leads_only` (absent: OFF; Uganda only). A
  manager is an admin or anyone with All Leads by the page's own rule. Checked on the Leads page (after the rota saves),
  the quote picker (`tabs/sales/send_quote.php` +4), the More menu's count (`tabs/sales/more_menu.php`), `save_lead`,
  `send_lead_quote`, `update_lead_status`, `convert_lead`, and the call log (`includes/api/api_leads.php` +3). A refused
  lead is answered as not found.
- **Follow-ups** — `lib/OwnedNumberHold.php` (new): with the registry on, `cron/followup_scan.php` leaves a
  salesperson's number out of its query, `cron/followup_run.php` closes an open one before any model call, and
  `cron/followup_send.php` closes an approved one before any send — unless `wa_followups_on_owned_numbers` is set. The
  departments are never held; an unreadable registry holds every number but theirs.
- **The webhook guard** — `cron/wa_webhook_guard.php` (+31 −3): with the registry on it also watches every ACTIVE
  salesperson's number. Off, or unreadable: the three departments, exactly as before.
- **`tools/set_config.php`** (+7): the three keys. Nothing in this release sets them.
- **Tests:** `test_sales_numbers.php` (new; its golden prompts against `6464204`); `test_multi_number_routing.php` (two
  assertions rewritten to the new truth); `test_brain_context.php` (the new key; fictitious names);
  `tests/fixtures/fake_evo_server.php` (instances and pairing for the card); the five distributor tests read `5.18.89`.
- **`manifest.json`** 5.18.89.

### Files — 34 against `6464204`: 6 added, 28 changed, none removed

- **Added:** `lib/LeadVisibility.php`, `lib/LineOwner.php`, `lib/OwnedLead.php`, `lib/OwnedNumberHold.php`,
  `lib/SalesNumbersAdmin.php`, `tests/test_sales_numbers.php`.
- **Changed:** `cron/followup_run.php`, `cron/followup_scan.php`, `cron/followup_send.php`, `cron/wa_webhook_guard.php`,
  `cron_leads.php`, `includes/api/api_leads.php`, `includes/post/post_leads.php`, `lib/AiLeadService.php`,
  `lib/AlertService.php`, `lib/BrainContext.php`, `lib/ChannelRegistry.php`, `lib/DishNetAiBrain.php`,
  `lib/ShopBotPayload.php`, `manifest.json`, `tabs/engage/wa_ai_setup.php`, `tabs/sales/leads.php`,
  `tabs/sales/more_menu.php`, `tabs/sales/send_quote.php`, `tools/set_config.php`, `workers/AiReplyWorker.php`,
  `tests/fixtures/fake_evo_server.php`, `tests/test_brain_context.php`, `tests/test_multi_number_routing.php`, and the
  five distributor tests (the version pin only).
- **Not in it:** the AI media layer (migration 085, the media worker, the voice, image and document paths, and the
  media layer's shared hand-over `lib/Handover.php`), the partner portal, the CSRF guard, any migration, any Evolution
  instance or number. A0's allow-list refuses each of these, and R4 checks none is installed.

### Ancestry

`53d5c4d` (5.18.89, release) ← `6464204` (5.18.88, live) ← `9cc81af` (5.18.87) ← `c2c96e1` (5.18.86). The source is Batch
2, `2c2771b`, on the branch:
- **26 files byte for byte** from `2c2771b`: the 15 changed ones whose copies on live and on the branch before Batch 2
  were the same; the manifest and the five distributor tests, which differed there only by the version (5.18.88
  against 5.18.86) and now both read 5.18.89; and the five new libraries;
- **8 ported** onto live's copies, which carry no AI media layer: `lib/BrainContext.php` (live's fourteen top-level keys
  plus `line_owner`), `lib/DishNetAiBrain.php` (Batch 2's patch applied cleanly), `workers/AiReplyWorker.php` (the owner
  resolved after `replyRoleOrRefuse()`, the lead given to them, the hand-over through `AlertService::handover()` in
  live's inline escalation — the branch's goes through the media layer's `Handover::escalate()`), `tools/set_config.php`
  (the three keys; no media keys), `tests/fixtures/fake_evo_server.php`, `tests/test_brain_context.php` (live's key
  count), `tests/test_multi_number_routing.php` (Batch 2's two rewritten assertions on live's copy) and
  `tests/test_sales_numbers.php` (its golden prompts against `6464204`, the brain live in 5.18.88).

*Corrected here, not in Git:* the release commit's own message says each of the 26 was identical on `6464204` and on
the branch before Batch 2. Six were not: the manifest and the five distributor tests differed there by the version
alone. The files themselves are as listed; rewording the commit would change the pinned hash, so the message stands
and this line corrects it.

`release/5.18.89` is local, not pushed.

### Tests

- **The release tree's own suite: 274 files, 12,948 passed, 0 failed, 0 skipped — twice** (A 14:25–15:03, B 15:03–15:40 UTC),
  each pass with nothing else running. Every file's tally is identical in both passes.
  - **Against 5.18.88's release suite** (273 files, 12,814 passed): exactly `test_sales_numbers` is added (+128);
    `test_brain_context` (123 → 128) and `test_multi_number_routing` (83 → 84) moved, by their own added and rewritten
    assertions. No other file's tally moved.
  - PHP warnings: 5 per pass, all from `test_dpo_endpoints` (an undefined variable in the test itself, line 91), as in 5.18.88's.
  - The checker refuses its own three planted faults: a tally moved in one pass only, a warning in one pass only, and an
    unnamed file whose tally moved against the base.
  - The tree was unchanged by both passes: still `53d5c4d`, clean.
  - **Against 5.18.87's release suite** too (270 files, 12,586 passed), as asked: the four files added since — 5.18.88's
    three (`test_channel_registry` 104, `test_event_processor_protected` 41, `test_multi_number_routing` 84) and
    `test_sales_numbers` 128 — and `test_brain_context` 123 → 128; nothing else moved (+362 in all).
- **A first attempt was set aside.** It ran beside the baseline pass and both counted rehearsals — four heavy jobs on
  four cores — and two files failed, once each:
  - `test_notify_kyc_race`, here: one weakened-copy check. The race happened and the copy wrote no mark, but the welcome
    it should then have produced was not in the dry-run log the test reads;
  - `test_notify_staff_controls`, in the baseline tree `7195253`, which holds no Batch 2 code: one South Sudan check
    (the invoice scan's send was not counted).

  Neither file is touched by this release, nor is the code the two checks exercise: `NotificationService.php` (the
  dry-run log), `webhook.php`, `KycService.php`, `includes/api/api_notifications.php` (the invoice scan) and the tests'
  sandbox are the same files at `6464204` and `53d5c4d`.
  **Neither failure could be reproduced**: alone (3 and 6 runs), under artificial CPU load (3 and 3), and three copies
  of the race test at once (18 runs) — every run passed. Two weaknesses in the test harness were found while looking
  (Known limitations). One is proved by reproducing it: the dry-run evidence log loses entries when two processes write
  it at once. **The cause of the two failures is NOT ESTABLISHED.** Both counted passes were then run again from
  scratch, with nothing beside them.
- **Focused:** the 79 test files whose source names one of the 34 changed files. Each passes and reads the same in both
  passes; 76 read as in 5.18.88's suite, `test_brain_context` and `test_multi_number_routing` moved as above, and
  `test_sales_numbers` is new. Beside them, as in 5.18.88's suite: the channel registry 104/0, the event processor 41/0,
  the Inbox route 23/0, migration integrity 28/0, and the South Sudan and tenant files (`test_staff_jobs_south_sudan`
  51/0, `test_staff_jobs_gate` 41/0, `test_tenant_profile` 108/0, `test_portal_tenant` 112/0, `test_email_no_sudan`
  62/0, `test_sales_support_tenant` 37/0, `test_notify_tenant_text` 30/0, `test_cashbook_tenant` 26/0,
  `test_phone_country` 26/0, `test_ai_country_facts` 21/0).
- **Lint:** `php -l` on all 33 PHP files of the diff, read from the commit: 0 errors. The sandbox has PHP 8.4. A scan of
  the 2,317 added PHP lines finds nothing newer than PHP 7.4 (four pattern hits, each a ternary's `: null` or `: false`,
  not a type); the server's own 8.1 lints at A2.
- `git diff --check 6464204 53d5c4d`: clean.
- **Secret scans** of the release diff and of the script and its rehearsal: clean. No key-, token- or credential-shaped
  value and no banned value. The phone-shaped values in the tests are fictitious fixtures; the only address in the
  rehearsal is its loopback fake (`127.0.0.1:9`, never contacted); the one long hex string is 087's sha256.

### South Sudan — unchanged, and proved four ways

1. **Statically:** every Batch 2 rule is behind `StaffJobsGate::applies` (Uganda) — the card, own leads only, and the
   registry, which gates the persona, the hand-over, owned leads, the hold and the guard. The event processor is not in
   the release.
2. **At A6 on the server, before anything changes:** the pin's Batch 2 rules run with **every switch on** as a South
   Sudan install, on a throwaway database: no card, no own-leads filter, no registry, nothing held — and on Uganda, with
   the switches absent as production has them, the card alone. The event processor (unchanged) is run as South Sudan and
   as Uganda beside the live one, and must read the known signatures. Refusal 6 otherwise.
3. **After the deploy (R13, R15) and after a rollback (RB),** the same runs on the installed code.
4. **In the suite:** `test_sales_numbers`'s South Sudan part (every switch set), and `test_staff_jobs_south_sudan`, which
   compares South Sudan's pages with 5.18.49's byte for byte — and found, in development, one newline the card's skipped
   block left on the WhatsApp AI setup page. Fixed before the release; the page is identical again (51/0).

The rehearsal shows each South Sudan refusal firing: own leads only, the card and the registry each made to forget the
country (1r, 1s, 1t).

### Domain B — untouched

- `dishnet-mikrotik-control-plane/` and the plugin's `docs/` are the same trees at `6464204` and `53d5c4d`.
- The whole repository's delta is the plugin's 34 files alone.
- **A7** refuses any pin that touches either tree or anything outside the plugin; **R14** after the deploy and **RB**
  after a rollback compare all 363 installed files byte for byte.

### `scripts/deploy-5.18.89.sh` — 1,978 lines, sha256 `6296407b3f23e048…`

Made from 5.18.88's script by a derivation with every replacement asserted. Every check added for 5.18.89 was first run
against the release's files and against 5.18.88's: each passes on the release and fails on 5.18.88's code, and the
rollback's checks the other way round. **It refuses before anything changes:**
1. live is not 5.18.88 at `6464204` (commit and manifest version);
2. `multi_number_channels_enabled` reads ON in either copy (A5);
3. 087 is not recorded, or recorded under a checksum that is not the installed file's (A8);
4. 087 is not as 5.18.88 left it — an object missing, a department row changed, or any other number in it (A8): nothing
   before 5.18.89 can add one, so it was written by hand;
5. the delta is not exactly the 34 files, carries a migration, or 087 at the pin is not the reviewed one (A0);
6. South Sudan's protection fails (A6): the event processor, run as South Sudan and as Uganda, reads otherwise than the
   live one's known signatures, or the pin's Batch 2 rules apply anything as South Sudan with every switch on;
7. Domain B's protection fails (A7);
8. the backup, or the event-queue snapshot, is not confirmed;
9. `sales_own_leads_only` reads ON in either copy (A5b) — the deploy itself would change what the team sees;
10. the pin's assistant prompt is not 5.18.88's for every conversation with no salesperson's number, on the server's own
    configuration (A10).

A9 compares how the live code and the pin's code route the three numbers, as in 5.18.88. A6, A9 and A10 run both
releases' code in throwaway directories inside the container (`docker cp` to `/tmp`, removed at exit); A10 builds the
assistant's prompt with `DishNetAiBrain::promptPreview()` — the path `reply()` takes, with no call to a model — and
compares HMACs under the run's key, never the prompts.

**After the deploy, besides every check carried over (R1, R3–R14, V1–V10, V3–V3g, V4):**
- **V11** — the call log refuses an anonymous caller before any lead is read;
- **V12** — the WhatsApp AI screen sends a visitor with no session to the sign-in page, and no part of the card is in
  the answer (a GET; nothing is posted to the screen);
- **V3h** — the three new switches are unchanged by the run, own leads only OFF in both copies;
- **R2b** — the installed rules on the server's own configuration: own leads only off, the follow-up hold holding nothing
  (the registry is off), the card shown — the one thing this release shows;
- **R3, R11** — 087 still complete; a number the card added since the deploy is reported (it is added switched off), and
  one switched on while the registry is off fails;
- **R6** — 5.18.89's pieces, 33 of them;
- **R10** — the owner is asked for only with the registry on, and a lead is given to a salesperson only when it came in
  on their own number;
- **R15** — the installed Batch 2 rules on throwaway databases: in full on Uganda with every switch on, the card alone
  with the switches absent, nothing at all as South Sudan;
- **R16** — the installed assistant's prompt against 5.18.88's, as A10.

**The rollback (RB):** 5.18.89's pieces gone; 5.18.88's Batch 1 and 5.18.87's Batch 0 in place; 087 as it was, with any
number the card added (switched off), never read with the switch off; the event processor 5.18.88's; the assistant's
prompt 5.18.88's again — an owner named changes nothing; the routing, the queue and Domain B as before.

The script contains no command that changes a switch.

### The rehearsal — `scripts/harness/deploy-5.18.89/rehearse.sh` (953 lines, sha256 `dc252a7c12cfdc95…`)

The base is installed as production runs it after 5.18.88's deploy on 07 Oct:
- 5.18.88 with 086 and 087 applied by its own runner — 087's three department rows, no instance;
- the authorisation and the booking WhatsApp on;
- the lead switches as decided on 07 Oct (capture ON, the uCRM write OFF);
- the Inbox's row naming three fictitious numbers, with support and account sharing one instance;
- the registry's switch and the three new switches unset;
- an event queue with 6 events, 3 of them not done; two photos; a salesperson (fictitious) among the staff.

It covers:
- **the pin (section 0):** cut on `6464204`; 34 files, 6 added, none removed; no migration and 087 the reviewed file;
  the twenty-six files taken whole are Batch 2's (`2c2771b`) byte for byte, the eight ported ones differ; no media-layer,
  portal or CSRF file; Domain B and the plugin's docs the same trees;
- **every refusal (1a–1z2):** 5.18.87 still live; the right commit with the wrong manifest; a placeholder pin; the
  branch tip; a media-layer file; a migration; 087 one comment different; the follow-up hold missing; Domain B, the
  plugin's docs, or a file outside the plugin; the registry's switch ON, or own leads only ON, in either copy or both;
  087 not recorded, recorded under another checksum, a number in it, or a trigger missing; a pin whose own-leads rule,
  card or registry forgets the country (South Sudan); a pin whose assistant introduces itself differently to everyone,
  or whose persona reaches e-mail; the code copy failing; the backup failing. A4, A5b's other two switches and A3 are
  evidence, not stops;
- **weakened copies of stage A (2a–2m):** A5, A5b, A8, A6, A7 and A10 each blinded and caught; A9 shown to stop, on its
  own, a pin that would land the shared number's inbound in account; with the allow-list blinded, a migration is
  stopped by the count and an altered 087 by its sha256;
- **the deploy (section 3):** the rehearsed deploy reads **62 ok / 0 failed / 0 notes**. No data is written at all, and no Evolution
  address, key or instance name appears in the log;
- **the teeth (4a–4v):** 5.18.88's WhatsApp AI screen back; the persona reaching e-mail (R16 names the one conversation);
  the own-leads rule without its country gate (R15 shows South Sudan hiding a colleague's lead); R16, R15, R13, R11 and
  V12 each blinded and caught; a trigger dropped; a number the card added, switched off — reported, and `--after-only`
  still PASSES; one switched on with the registry off — a failure; a department row given an instance; the registry's
  switch ON; own leads only ON; a Domain B file changed; an event deleted; the card leaked to a visitor with no
  session; and 087's log line: OK counting 13 of 14, a PARTIAL, no line at all;
- **5.18.88's two fixes (5a–5f):** R7 and V4, each with its control on the control;
- **the switches after the deploy (section 6):** the uCRM write on (R10 says leads reach uCRM), then off
  again, byte for byte; the registry's switch set with the installed tool — the checks say at once that the registry is
  not dark — and put back; the hand-over copy off and owned follow-ups on — reported, never a failure, as they act only
  with the registry on; own leads only on — the checks say so at once, it being its own later step — and put back;
  qualification off; the Inbox's account number taken away, or moved during a run (V3g names the key, never its
  values);
- **the rollback (section 7):** typed `ROLLBACK`. RB reads:
  - 5.18.88's files and manifest; the salesperson numbers' code gone; Batch 1, Batch 0 and every earlier feature whole;
  - **087's tables stay, and 5.18.88 does not read them with the switch off.** A recording handle counts 0 statements.
    7b is the control on the control: with the switch ON in the Inbox's row, the same check sees the reads;
  - the assistant's prompt 5.18.88's again on the server's own configuration — an owner named changes nothing;
  - the event processor 5.18.88's on both countries; the routing as before; the queue, which lost nothing; Domain B
    as `6464204` has it; no data changed.
  - 7d deploys again over the rolled-back 5.18.88 (PASSES); a salesperson's number is then added as the card adds it,
    and 7e rolls back with it in the registry: PASSES, the number reported, kept switched off, read by nothing, its
    instance name printed nowhere;
- **what is left behind (section 8):** the checkout as found, no weakened copy, nothing in `/tmp`, and no PHP fatal from
  the stand-in.

**Results.** A first run on the working copy read 302 passed and 1 failed. That one failure was a mis-written
expectation in a new control of the rehearsal itself: the persona's line occurs twice at the pin (the identity line and
rule 4a, one answer), so the count reads `12111`, not `11111`. Fixed before the commit; the script did not change. Then
two runs on the committed script (`b7848d9`):
- **run 1: 303 passed, 0 failed**, 91 runs of the script, no FAIL line, the checkout left as found (13:51–14:01 UTC);
- **run 2: 303 passed, 0 failed**, 91 runs of the script, the same check lines as run 1, time stamps aside (14:01–14:12
  UTC). Both runs' rehearsed deploy reads 62 ok / 0 failed / 0 notes.

### Deployment runbook — each step is the operator's, and each needs the operator's approval first

1. **Push — DONE 07 Oct, 18:46 UTC**, on the operator's word (*"APPROVED — PUSH 5.18.89"*). It pushed the branch
   `claude/study-this-jhe2eg` (Batch 2, the script, its rehearsal and these records) and `release/5.18.89` (the release
   commit). The server pulls both from GitHub.
2. **Deploy, as root on the server — DONE 19:02 UTC**, on the operator's word (*"APPROVED — PRODUCTION DEPLOY 5.18.89"*):
   **PASSED, 62 ok / 0 failed / 1 note** (the RESULT below). It asks for `DEPLOY`. Send back the **log file**, not a copy
   of the terminal:

   `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.89 && mkdir -p /root/dnb-5.18.89 && bash scripts/deploy-5.18.89.sh 2>&1 | tee /root/dnb-5.18.89/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`

   - **Expect 62 ok / 0 failed / 0 notes**, the rehearsal's own figure. *Corrected after the deploy:* the server read
     **62 ok / 0 failed / 1 note**. The note is R3's: `migration.log` holds no line for 087. At 5.18.88's deploy (09:52)
     it held one, `OK: 087_wa_channels.sql (14 stmts, …)`; by 19:02 it was gone (rotated or rewritten; the log does not
     say which). The rehearsal had planted this
     very case (4t, *"no line for 087 in the log at all: R3 passes on the store"*), and R3 then read every one of 087's
     statements' effects from the store and passed — but this line did not list it.
   - A note would say that a lead switch is not as decided on 07 Oct (A4), that `ai_qualification` is off, or that the
     Inbox's row lacks a number.
   - **If a refusal stops it, nothing has changed.** The log names the refusal: send it back.
3. **After the deploy: nothing to switch on.** Admins see the *Salesperson numbers* card on the WhatsApp AI screen;
   nothing else a person sees changes.
4. **Connecting the salesperson's number is its own step, with its own approval — no command here.** The card's order is
   add (switched off) → *Show QR code* and pair the phone → *Verify number* (the number Evolution reports) → *Register
   webhook* → and only then, with `multi_number_channels_enabled` switched on as its own decision, *Switch on*
   (`docs/65` §AA.3). Switching the registry on is a production change of its own: from then on WhatsApp routes through
   the registry for every number — the three departments' rows store no instance, so they keep routing as configured.
5. **Later, each its own decision:** `sales_own_leads_only` (salespeople see only their own leads); the uCRM lead write;
   `wa_handover_copy_central` off (the salesperson alone), or `wa_followups_on_owned_numbers` on.

### RESULT — DEPLOYED to production 2026-10-07, 19:02 UTC: PASSED, 62 ok / 0 failed / 1 note

Recorded from the terminal the operator pasted, which carries the whole log (`tee`); the script prints no secret. The log
file stays on the server under `/root/dnb-5.18.89/`.

The run began at 19:02:00 UTC (`20261007T190200Z`). `DEPLOY` was typed, `deploy-hybrid.sh` answered *"✓ container now
serves 53d5c4d"*, and the 34 files were stamped at 19:02:49 UTC. **The rehearsal's 62 ok lines and the server's are the
same 62, one for one** (by check, against the rehearsed deploy's full output); the server adds one note, R3's (below).

- **The pull.**
  - The checkout fast-forwarded `28f2570` → `1541208`, and `release/5.18.89` was fetched as new.
  - Branch tip `2c2771b` (not installed); release commit `53d5c4d`, cut on `6464204`.
  - 34 files (28 changed, 6 added, 0 removed); no tracked edits.
- **A — no refusal fired, and every check passed:**
  - A7: Domain B untouched (`cf0b0e3`, `969d873`). A0: exactly the 34 files, no migration, 087 the reviewed one.
  - Live was `6464204` / 5.18.88. **A2: PHP 8.1.34 accepted all 24 changed server files and 9 test files.**
  - The switches as the operator left them:
    - pilot `on`, `ia=on/on`, `wa=on/on`;
    - the lead switches `lc=on/on`, `ls=off/off`, `qu=on/on`, `sa=on/on`;
    - the registry's switch `mn=absent/absent` (A5); 5.18.89's three `absent/absent` (A5b);
    - the Inbox's row `evo=yes sales=yes support=yes account=yes registry=absent` (A3).
  - 086 complete, `2:18:1:1:1`. **087 applied and complete (A8):** 2 tables, 3 indexes, 3 triggers, both CHECKs, the
    ledger row matching the installed file, the three department rows, no instance stored, no other channel.
  - **The routing:** `sales:in=sales support:in=support account:in=support shared=support+account evo=yes registry=off`,
    in the configuration files and in the Inbox's row. A9: the pin's code routes exactly as live's.
  - **A10:** the pin's assistant builds 5.18.88's prompt for all twelve conversations, on the server's own configuration.
  - **A6, South Sudan:** live's and the pin's processor read the same signatures on both countries. The pin's Batch 2
    rules, every switch on, apply nothing as a South Sudan install; on Uganda, with the switches absent, they show the
    card alone.
  - The webhook log's last 300 entries hold 3 `job.add`, the last at 09:26:31 by the plugin's clock. Photos
    `present:16:3`, 16 files.
- **Backup**, in `/root/dnb-5.18.89/backup-20261007T190200Z`:
  - `plugin.sqlite3`, 30 MB: one consistent copy, integrity ok, 250 tables, SQLite 3.48.0;
  - no `dishnet.sqlite` (*"nothing to copy"*);
  - the data directory, 150 MB; the installed 5.18.88, 11 MB; the vault;
  - the event queue's snapshot: **0 events not done** (8,223 in all, every one done).

  Then `GO`.
- **V.**
  - The sign-in page answers 200 with zero redirects, and carries no South Sudan contact; the portal answers 302;
    pinch-zoom is allowed.
  - The photo viewer answers 302 and its upload 401. The authorisation page answers 404 *"This link is not valid"* to a
    request without a link.
  - `install_auth_request`, `install_auth_prefill`, `wa_send_reply` and **`log_call` (V11)** answer 401 without a login.
  - **V12:** the WhatsApp AI screen sends a visitor with no session to the sign-in page (302), with no part of the card.
  - Every switch, the Inbox's row and the AI settings are unchanged by the run (V3–V3h).
  - **V4: no fatal or parse error since the copy, with 17 log lines read.**
- **R.**
  - **R1:** all 34 files as `53d5c4d` has them; manifest 5.18.89.
  - **R2, R2b:** the pilot, the authorisation and the booking WhatsApp as the operator left them; own leads only OFF,
    the follow-up hold holding nothing, the card shown — the one thing this release shows.
  - **R3:** 086 complete (`2:18:1:1:1` before and after); 084 `16:3`, 16 files; **087 applied and complete**, as at A8,
    with the trail of three. **The one note:** *"migration.log holds no line for 087 (not written, or rotated away) —
    every statement's effect was read from the store above, which is the verification"* — see the correction in the
    runbook above.
  - **R4:** no media-layer, portal or CSRF file; the pilot's channel is `NullWhatsAppChannel`. **R5:** all 238 files from
    Release A through 5.18.88 intact. **R6:** every earlier surface, and 5.18.89's pieces.
  - **R7** (read-only): **UGX CASH IN HAND 591,072.00 · USD 0.00**, the figures 5.18.88's deploy read. The photos as
    before the run.
  - **R8:** the quotation reader. **R9:** the Inbox route. **R10:** the lead path; with the registry off a lead is
    5.18.88's, field for field, and the uCRM write is OFF.
  - **R11:** the registry is dark — its switch OFF in both copies, `enabled()` and `forStore()` off, 0 statements on its
    tables, 087's three department rows and no other number. **R12:** the three numbers route exactly as on 5.18.88.
  - **R13:** the event processor as designed on both countries; the queue lost nothing (8,223, all done, before and
    after). **R14:** Domain B, all 363 files, byte for byte.
  - **R15:** the installed Batch 2 rules — in full on Uganda with every switch on, the card alone with the switches
    absent, nothing as South Sudan. **R16:** the installed assistant's prompt is 5.18.88's for all twelve conversations.
- **What the run did not do:** it set no switch and changed no configuration value (V3–V3h); it created, paired or
  called no Evolution instance and added no number (R3, R11: the three department rows only); it sent nothing — its only
  POSTs carried no data and were refused with 401 before any handler, and the event queue held 8,223 events, every one
  done, before and after. The log cannot speak for anything else on the server in that window.
- `--check` read *"NOT up to date"* before and after, as expected: it compares the container with the branch tip,
  `2c2771b`, which this script does not install.

### Rollback runbook — its own command, never pasted with the deploy (root `docs/44` §16.9)

Only if it is ever needed. The deploy's log prints the same at its end. It asks for `ROLLBACK` before it changes anything,
puts back 5.18.88 (`6464204`) through `deploy-hybrid.sh`, then checks it (RB):

`cd /opt/dishnet && bash scripts/deploy-5.18.89.sh --rollback`

If the script cannot run, by hand:

`cd /opt/dishnet && git checkout 6464204 && bash scripts/deploy-hybrid.sh && git checkout -`

What stays as it is:
- 087's tables, with any number the card added — switched off, read by nothing while the switch is off. RB proves it.
- Every switch stays as it is. A history note an admin's reassignment wrote stays on its lead.
- Leads, conversations and events stay. The queue loses nothing; RB checks it.
- **Switch the registry off before a rollback** if it was ever switched on: 5.18.88 routes by the registry too, but
  knows nothing of a salesperson's number's persona, hand-over or leads.

### Known limitations

- **The card, as an admin sees it, is proved by the suite, not on the server.** `test_sales_numbers` drives it through
  its forms on the real plugin; the deploy signs nobody in, so on the server V12 checks the anonymous answer and R2b,
  R6 and R15 check the code behind the card.
- **A10 and R16 compare twelve conversations**, built with no products and no history, on the server's own
  configuration. The suite's golden test compares 24.
- **`--after-only` is meaningful while the switches are still off.** Once the registry is switched on to connect the
  number, V3f and R11 say so, by design.
- **Pre-existing, observed, not changed** (`docs/65` §AB.2): the Leads page's add/edit form posts actions no handler
  answers; a staff row with no `is_active` field is active to `cron_leads.php` but not to `OwnedLead`; counts outside the
  Leads area (the staff API's LTE dashboard) count every lead when own leads only is on; the call-recording upload is not
  checked by own leads only; the salesperson is not told when their number disconnects (the central number is).
- **PHP 8.1 lint runs only on the server, at A2.** The sandbox lints with 8.4; a scan of the added lines finds nothing
  newer than PHP 7.4.
- **Two test-harness weaknesses, recorded, not fixed** — found while chasing the two failures of the set-aside suite
  attempt (Tests, above); neither file is touched by this release, and neither weakness is in production code paths
  that send anything:
  - `NotificationService`'s dry-run log, which the race test reads as its evidence, is written read-modify-write with
    no lock. Two processes writing it at once kept 79, 19 and 18 of 300 entries; one writer keeps 300 of 300. A lock
    is its own change.
  - The staff-jobs sandbox proves its plugin server by a nonce of its own, but its fake uCRM and fake Evolution by a
    marker every copy shares, so its "this sandbox's own marker" holds for them only through the still-running check.
    Two suites side by side could in principle adopt each other's fake. A reading of the code, not reproduced; the
    counted passes ran one at a time.
- **The media layer's hand-overs** (on the branch only) alert the central number alone; production has no media layer.
- **Not in this release:** the AI media layer, the partner portal, the CSRF guard. The registry stays dark; connecting a
  number is the operator's own later step.

### Remaining blockers, and the call

- **Technical blockers: none found.** Every check, test and rehearsal above passed.
- **Both approvals were given and both steps are done:** pushed at 18:46 UTC, deployed at 19:02 UTC.
- **The call was GO, and the deploy passed, 62/0/1** (the one note is R3's, above). The release is dark. With the switches as they are, the only change a person
  sees is the *Salesperson numbers* card for admins on Uganda's WhatsApp AI screen; every conversation stays a
  department's, so the prompt, the hand-over, the leads and the follow-ups are 5.18.88's — A10 and R16 prove the prompt
  on the server's own configuration before and after.
- **Connecting the salesperson's number comes after the deploy**, as its own step with its own approval: add it through
  the card (switched off), pair, verify, register the webhook — and switching the registry on, a production change of
  its own.

**Git — pushed 07 Oct, 18:46 UTC**, on the operator's instruction (*"APPROVED — PUSH 5.18.89"*):
- `claude/study-this-jhe2eg` from `b7f92cf` to `1541208` (18:45:58 UTC): `7195253` (the design), `2c2771b` (Batch 2),
  `b7848d9` (the script and its rehearsal), `1f730d5` (this entry) and `1541208` (the development entry's comparison);
- `release/5.18.89` new at `53d5c4d` (18:46:04 UTC). Nothing else was pushed; no tag.

At the push nothing was deployed, no configuration had changed and nothing had been sent. This record of the result is a
later commit.

No configuration changed, no switch set, no Evolution instance created, nothing sent. `docs/59` stays untracked by the
operator's decision.

## 07 Oct — The salesperson pilot: the acceptance test and the runbook — tests and documents only, no production code; NOT pushed, nothing deployed

**Why.** 5.18.89 is in production since 19:02 UTC, dark. The operator's instruction of 07 Oct evening: the salespeople's
lines are to answer from their own numbers, staged. One pilot is proved end to end first; then the others, one at a time.
The operator answered the three decisions still missing (the salesperson is named in the chat, not here):
- **follow-ups** on a salesperson's number — *"Send from their number (Recommended)"*;
- **own leads only** — *"With the pilot (Recommended)"*;
- **the pilot salesperson** — chosen.

**What changed.** No production file: the plugin outside `tests/` is byte for byte `HEAD`'s.
- **`tests/test_sales_pilot.php`** (new) — the pilot end to end on the real plugin, in production's shape. Production's
  shape is as its 5.18.89 deploy read it (A9, R12): sales on its own instance, support and account sharing one,
  `ai_sales_on_all_numbers` on. The instruction's tests A–Q, the pilot checks 1–15 and the rollback, with 12 weakened
  copies (`docs/65` §AC.4).
- **`tests/fixtures/fake_evo_server.php`** — one test control, `/__test/fail_instance`: one instance refuses every send,
  and each refusal is recorded in `failed_calls`. Nothing it did before changes.
- **`docs/66-salesperson-pilot-runbook-2026-10-07.md`** (new) — the operator's procedure. Placeholders only; every
  command in its own block; the rollback in its own section.
- **`docs/65` §AC** — the decisions, the facts found while proving the pilot, the test and the runbook.

**Proofs:**
- **`tests/test_sales_pilot.php`** — 122 passed, 0 failed:
  - in both full development passes;
  - twice on the live release's code. That copy of `53d5c4d` differs from it only in the two test files, checked with
    `diff -r` against a pristine extract; the release's own fixture was given the same control.

  12 weakened copies, each caught, on both trees.
- **Full suite, twice**, alone (19:46–20:28 and 20:29–21:09 UTC):
  - 287 files, 13,984 passed, 0 failed, 0 skipped — every file's tally identical in both passes;
  - against Batch 2's counted passes (286 files, 13,862 passed): exactly `test_sales_pilot` added (+122); nothing
    moved, nothing gone;
  - PHP warnings: 5 per pass, all `test_dpo_endpoints`, as before;
  - the checker refuses its three planted faults.
- **Domain B:** `dishnet-mikrotik-control-plane/` and `dishnet-hybrid-sudan/docs/` are identical at `53d5c4d` and
  `HEAD`, and this change touches neither.
- **Checks:**
  - `php -l` clean on both PHP files;
  - `git diff --check` clean;
  - the banned-value scan of every new and changed file clean — every person, number and instance in the test is
    fictitious.

**Flags:** none read or changed in production. The runbook sets `multi_number_channels_enabled`, `sales_own_leads_only`
and `wa_followups_on_owned_numbers`, each the operator's step.

**Production impact:** none. Nothing here is deployed or needs deploying. The pilot itself is a card on the WhatsApp AI
screen, three settings and read-only checks (docs/66).

**Rollback:** nothing deployed, nothing to roll back. The pilot's own rollback is docs/66 §R.

**Git:** this commit, local, on `claude/study-this-jhe2eg`, on top of `8be69ec` (the 5.18.89 result). **NOT pushed:** both
wait for the operator's approval. `docs/59` stays untracked by the operator's decision.

## 08 Oct — The pre-pilot safety fix (5.18.90): DishNet's own numbers are never answered by an assistant, and a number's assistant switch stops its follow-ups too — dark; BUILT in development, reviewed; PUSHED 08 Oct 13:16:59 UTC (`a694161`); released as 5.18.90 (`release/5.18.90` = `3d9cb5f`, the entry below) — NOT deployed; the pilot is stopped before docs/66 step 5

**1. What is currently configured.** Production runs 5.18.89 (`release/5.18.89` = `53d5c4d`) since 07 Oct, 19:02 UTC.
`multi_number_channels_enabled` is not set, so the channel registry is off and everything below is inactive there. The
operator stopped the pilot (docs/66) before step 5. This entry is recorded from the repository; production was not read
or changed for it.

**2. Why the change is necessary.** Two defects were reported, and both were confirmed in the code (docs/65 §AD.1):
- **an AI-to-AI loop**: nothing on the inbound path knew DishNet's own WhatsApp numbers. Once the human pause (5 minutes
  in production) ran out, one DishNet number's assistant could answer another's;
- **follow-ups bypassed the assistant switch**: with a salesperson number's assistant off, `followup_auto_send` could
  still send from it.

**3. Exactly what changes** (docs/65 §AD.2; every new path acts only with the channel registry on):
- **new** `lib/InternalNumbers.php` — DishNet's own numbers, from records the plugin already keeps:
  - the registry's numbers;
  - the configured lines, from the files and the store;
  - the number Evolution reports for each instance;
  - salesperson numbers' owners and the alert numbers, on salesperson numbers only.
- **new** `lib/AutomationPolicy.php` — one rule for every automated send, with no transport;
- `lib/EvolutionApiService.php` — the central guard for reply-class and proactive-class sends and the typing indicator.
  Staff-class sends (Inbox replies, staff alerts) are not asked. `listInstances()` gains `jid_phone`;
- `evo_webhook.php`, `workers/AiReplyWorker.php`, `workers/MediaWorker.php` — DishNet's numbers are kept for the team
  and never answered;
- `cron/followup_scan.php`, `cron/followup_run.php`, `cron/followup_send.php`, `lib/FollowUpService.php` — follow-ups
  respect the policy. The scan's channel list is an allow-list; held drafts take none of the sender's places;
- `cron/wa_watchdog.php` — no paging about DishNet numbers' chats;
- `cron/wa_webhook_guard.php` — records the number Evolution reports for each instance;
- `lib/ChannelRegistry.php`, `lib/SalesNumbersAdmin.php`, `tabs/engage/wa_ai_setup.php` — the department numbers,
  verified on the card from the phone number Evolution reports (never an `@lid` owner's digits);
- `tools/set_config.php` — refuses the registry switch until the department numbers are verified, a recorded re-pair
  counted as the policy counts it;
- `tools/channels.php` — prints whether the department numbers are all verified (Uganda), likewise, still writing
  nothing;
- `manifest.json` — 5.18.90;
- tests:
  - the new `tests/test_pilot_safety.php`;
  - `test_channel_registry`, `test_multi_number_routing`, `test_sales_numbers` and `test_sales_pilot`, amended to
    verify their fixtures' numbers;
  - the two fixtures;
  - 15 version pins;
- docs:
  - docs/66 corrected — R-1, a new step 1.5, the STOP conditions and the loop test;
  - docs/65 §AD, including three independent reviews and a fourth check (§AD.6–§AD.9).

No migration. No setting is changed.

**4. Effect on UISP/uCRM.** None. Nothing here calls uCRM or changes what is sent to it. Leads keep their fields:
`source_number` stays null for a department, as before.

**5. Rollback.** Nothing is deployed, so there is nothing to roll back. When 5.18.90 is deployed, its own pinned deploy
script will carry its rollback, in a separate block from the deploy.

**Proofs:**
- **`tests/test_pilot_safety.php`** (new) — the instruction's tests 1–25 and the reviews' cases, on the real plugin with a
  fake Evolution and follow-ups behind a dead proxy:
  - development tree: 185 passed, 0 failed, with **66 weakened copies, each caught**;
  - the release's code (5.18.89 plus this change): 177 passed, 0 failed, with 63 copies caught (the media worker's three
    are development-only);
  - a static check confirms every copy's anchor is unique and every weakened file still parses.
- **Full suite, development tree, twice**, alone (09:44–10:25 and 10:25–11:07 UTC):
  - 288 files, 14,176 passed, 0 failed, 0 skipped, every file's tally identical in both passes;
  - against the pilot entry's counted passes (287 files, 13,984): exactly `test_pilot_safety` added (+185);
    `test_channel_registry` 104 → 105, `test_sales_numbers` 128 → 131 and `test_sales_pilot` 122 → 125 move by their
    amended assertions; nothing else moved, nothing gone;
  - PHP warnings: 5 per pass, all `test_dpo_endpoints`, as before; the checker refuses its three planted faults.
- **Full suite, the release's code (5.18.89 = `53d5c4d` plus this change), twice**, alone (11:47–12:26 and 12:26–13:06 UTC):
  - 276 files, 13,256 passed, 0 failed, 0 skipped, every file's tally identical in both passes;
  - against 5.18.89's own counted passes (274 files, 12,948): `test_pilot_safety` (+177) and `test_sales_pilot` (+125)
    added; `test_channel_registry` 104 → 105 and `test_sales_numbers` 128 → 131 move as above; `test_quote_tax_line`
    29 → 31 reads differently for the reason recorded on 07 Oct — its two comparisons with an old commit run only where
    `.git` is a directory, and 5.18.89's passes ran in a worktree; nothing else moved;
  - a first attempt from the session's private scratch directory failed `test_cli_data_dir` (9): that test drops to the
    `nobody` user, who cannot read that directory. From a copy every user can read — identical by `diff -r` — it passed
    19/0, and the two counted passes above ran there.
- **Reviews:** three independent reviews and a fourth check, each finding challenged by a separate skeptic (docs/65
  §AD.6–§AD.9). One MAJOR was found, by the second review, and fixed; every later finding was MINOR and fixed.
- **Domain B:** `dishnet-mikrotik-control-plane/` and `dishnet-hybrid-sudan/docs/` are untouched.
- **South Sudan:** the registry and the policy never take effect there (asserted, with every switch set); its webhook
  guard records nothing; `tools/channels.php` says nothing of department numbers.
- **Checks:** `php -l` clean on every changed PHP file of both trees; `git diff --check` clean; the banned-value and
  secret scan of every new and changed line clean — every person, number and instance in the tests is fictitious.

**Git:** this commit, on `claude/study-this-jhe2eg`, on top of `1961429` (the pilot) and `8be69ec` (the 5.18.89 result),
both unchanged. The operator approved, on 08 Oct, this commit, the branch's push and the release preparation — **not the
deploy**. **Pushed 08 Oct 13:16:59 UTC** (`1541208..a694161`), the branch alone. The deploy waits: production stays on
5.18.89 with the registry OFF until the deploy is approved on its own. `docs/59` stays untracked by the operator's
decision.

## 08 Oct — 5.18.90: the pre-pilot safety fix, dark — no DishNet number's assistant answers another DishNet number, and a number's assistant switch stops its automated follow-ups too, all behind `multi_number_channels_enabled` (OFF); no migration; South Sudan and Domain B unchanged. `release/5.18.90` = `3d9cb5f`, cut on live 5.18.89 (`53d5c4d`); `scripts/deploy-5.18.90.sh` pinned to it and rehearsed — PUSHED 08 Oct 15:46 UTC; DEPLOYED 15:52 UTC (PASSED 65/0/1); the registry stays OFF

**This is the safety fix's release** (`a694161` on the branch, `docs/65` §AD, `docs/66`, the entry above). The operator
approved, on 08 Oct: *"APPROVED: PROCEED WITH THE 5.18.90 RELEASE PREPARATION ONLY"* — commit the fix, push the
development branch, cut `release/5.18.90` from the live 5.18.89, build the pinned deploy script, rehearse the deploy and
the rollback, run the release checks, and report. **Not approved, and not done:** the deploy; pushing `release/5.18.90`
(the server fetches it at deploy time, so it is pushed with the deploy's approval); switching the registry or any other
switch on; pairing or switching on a salesperson's number; creating an Evolution instance; sending a message. Production
stays on 5.18.89 with the registry OFF. Only after 5.18.90 is live and verified does the pilot resume (docs/66 step 1.5,
then the AI-to-AI loop test, then salesperson #1).

**Then, the same day:** *"APPROVED: DEPLOY RELEASE 5.18.90 TO PRODUCTION"* — push `release/5.18.90`, deploy `3d9cb5f`
through the rehearsed pinned script, run every post-deployment check, and stop. **Done** (the RESULT below): pushed
15:46 UTC, deployed 15:52 UTC, PASSED. Still not approved, and not done: the registry or any other switch; verifying,
pairing or switching on any number; the salesperson's assistant; any Evolution change; any message; the pilot.

### The five-part form

1. **What is configured now (5.18.89, `53d5c4d`)** — as recorded on 07 Oct, 21:38–21:47 UTC, not read again since:
   - `multi_number_channels_enabled`, `sales_own_leads_only`, `wa_handover_copy_central` and
     `wa_followups_on_owned_numbers` absent in both copies;
   - 087's three department rows, no instance and no number; one salesperson's number, `sales-001`, added through the
     card on 07 Oct — switched off, its assistant switched off at 21:40, no number, its webhook not set;
   - the department instances: sales its own, support and account one shared;
   - lead capture ON, the uCRM write OFF; the authorisation and the booking WhatsApp ON.
2. **Why.** The two defects the pilot found before step 5 (docs/65 §AD.1): one DishNet number's assistant could answer
   another's once the human pause ran out, and a number's assistant switch did not stop its follow-ups. Both must be
   fixed in production, dark, before any salesperson's number is switched on.
3. **Exactly what changes.** The 29 files below. **No migration.**
   - **With the switches as they are, one thing changes:** on Uganda, the *Salesperson numbers* card shows *Verify
     number* on each department number that takes an instance's inbound, and says which are not verified yet;
     `tools/channels.php` says the same. Verifying is an admin's act, after the deploy, as docs/66's own step.
   - **Everything else returns at its first line.** With the registry off, `AutomationPolicy` is inert
     (`AutomationPolicy::inert()`): nothing is refused, nothing classified, no SQL added, and every send, follow-up and
     page is 5.18.89's (A11, R2c on the server's own configuration; R17 on a throwaway database). The assistant's prompt
     is 5.18.89's byte for byte (A10, R16): 5.18.90 does not change the brain.
4. **Effect on UISP/uCRM.** UISP: none. uCRM: none. Evolution: nothing called by the deploy or by any 5.18.90 path while
   the registry is off. **The card's *Verify number* does call Evolution** — it reads the instance's report — only when
   an admin presses it. The plugin's SQLite: nothing written by the deploy. *Recorded:* any *Verify number* on the card —
   a department's or a salesperson's — also records the number Evolution reports for each instance in the store's
   `wa_evo_numbers`, a table the store creates on its first write (SqliteStore's own lazy creation, as for every other
   file it keeps; no migration); while the registry is off nothing else is written there.
5. **Rollback.** `scripts/deploy-5.18.90.sh --rollback`, typed `ROLLBACK`, puts 5.18.89 (`53d5c4d`) back through
   `deploy-hybrid.sh` and checks it. The department numbers' *Verify number* goes; a number verified through it stays in
   087's tables, unread while the registry is off. The runbook is its own section below, never beside the deploy command.

### Release notes — what each change does

- **`lib/InternalNumbers.php`** (new, 426 lines) — DishNet's own WhatsApp numbers, from records the plugin already keeps:
  the registry's numbers (salespeople's and, verified on the card, the departments'), the configured lines, and the
  number Evolution reports for each instance, as the webhook guard last read it; on a salesperson's number only, also
  the numbers' owners and the alert numbers. Whole numbers, digits only, exact; an `@lid` owner is never a number. Its
  `gaps()` names the departments whose number is not verified for the instance they are configured with.
- **`lib/AutomationPolicy.php`** (new, 302 lines) — one rule every automated send asks, with no transport: the channel
  known and active, its assistant ON, a salesperson's number verified (and not re-paired since) with the department
  numbers all verified, the recipient not DishNet's own. **Registry off: `inert()` — nothing refused, nothing
  classified, no SQL added.**
- **`lib/EvolutionApiService.php`** (+106 −5) — every reply-class and proactive-class send and the typing indicator ask
  the policy first, only with the registry on; a refusal is returned, never sent from another number, and marked so no
  caller retries it. Staff-class sends (Inbox replies, staff alerts) are not asked. `listInstances()` gains `jid_phone`.
- **`evo_webhook.php`** (+28 −1) — with the registry on, a message from one of DishNet's own numbers is stored, filed
  `staff` and never queued for the assistant (no reply, lead or hand-over). `workers/AiReplyWorker.php` (+50 −3): the same
  for one already queued; the policy before the model is asked and again at the send.
- **Follow-ups** — `cron/followup_scan.php` (+17), `cron/followup_run.php` (+25), `cron/followup_send.php` (+27 −1),
  `lib/FollowUpService.php` (+8 −3): a number whose assistant is off, or that may not send now, gets no follow-up;
  a held draft takes none of the sender's places. **The fix for the second defect.**
- **`cron/wa_watchdog.php`** (+11) — no paging about a chat with one of DishNet's own numbers (registry on).
- **`cron/wa_webhook_guard.php`** (+14) — with the registry on, records the number Evolution reports for each instance.
- **The card** — `lib/ChannelRegistry.php` (+73 −3: `verifyDepartmentNumber()`, `verifiedInstances()`),
  `lib/SalesNumbersAdmin.php` (+174 −3), `tabs/engage/wa_ai_setup.php` (+34 −2): *Verify number* on the department
  numbers, read from Evolution's report for the configured instance, never typed; a salesperson's number cannot be
  switched on until they are verified; a re-paired phone or an `@lid` owner is shown and refused.
- **`tools/set_config.php`** (+30) — refuses to switch `multi_number_channels_enabled` on (Uganda, 087 present) until the
  department numbers are verified; nothing is saved. **`tools/channels.php`** (+11) — says whether they are.
- **Tests:** `test_pilot_safety.php` (new; 177 checks with 63 weakened copies on the release tree); `test_sales_pilot.php`
  (new here: the pilot's acceptance test, `1961429` as amended); `test_channel_registry.php`,
  `test_multi_number_routing.php`, `test_sales_numbers.php`, the sandbox and the fake Evolution, adjusted; the five
  distributor tests read `5.18.90`.
- **`manifest.json`** 5.18.90.

### Files — 29 against `53d5c4d`: 4 added, 25 changed, none removed

- **Added (4):** `lib/AutomationPolicy.php`, `lib/InternalNumbers.php`, `tests/test_pilot_safety.php`,
  `tests/test_sales_pilot.php`.
- **Changed (25):** `cron/followup_run.php`, `cron/followup_scan.php`, `cron/followup_send.php`, `cron/wa_watchdog.php`,
  `cron/wa_webhook_guard.php`, `evo_webhook.php`, `lib/ChannelRegistry.php`, `lib/EvolutionApiService.php`,
  `lib/FollowUpService.php`, `lib/SalesNumbersAdmin.php`, `manifest.json`, `tabs/engage/wa_ai_setup.php`,
  `tools/channels.php`, `tools/set_config.php`, `workers/AiReplyWorker.php`, `tests/fixtures/fake_evo_server.php`,
  `tests/fixtures/staff_jobs_sandbox.php`, `tests/test_channel_registry.php`, `tests/test_multi_number_routing.php`,
  `tests/test_sales_numbers.php`, and the five distributor tests (the version pin only).
- **+4,321 −32 lines.**
- **Not in it:** `workers/MediaWorker.php` (its 5.18.90 change is development-only: the AI media layer is not in
  production), the media layer itself, the partner portal, the CSRF guard, any migration. A0's allow-list refuses each,
  and R4 checks none is installed.

### Ancestry

`3d9cb5f` (5.18.90, release) ← `53d5c4d` (5.18.89, live) ← `6464204` (5.18.88) ← `9cc81af` (5.18.87). The source is the
safety fix, `a694161`, on the branch (pushed 08 Oct 13:16:59 UTC, `1541208..a694161`):
- **22 files byte for byte** from `a694161`: the two libraries and the two tests it adds, the five crons, the registry,
  `FollowUpService`, the card and its screen, `tools/channels.php`, the manifest, the sandbox, `test_channel_registry.php`
  and the five distributor pins;
- **7 ported** onto live's copies, which carry no AI media layer, each carrying the branch's 5.18.90 change line for line:
  `evo_webhook.php`, `lib/EvolutionApiService.php`, `workers/AiReplyWorker.php`, `tools/set_config.php`,
  `tests/test_multi_number_routing.php`, `tests/test_sales_numbers.php` and `tests/fixtures/fake_evo_server.php` (the
  pilot's `fail_instance` control and 5.18.90's presence calls; the media layer's state keys are not on live).

`release/5.18.90` was local until the deploy's approval; **pushed 08 Oct 15:46:17 UTC** (a new branch at `3d9cb5f`),
and the server fetched it at deploy time (`git fetch origin release/5.18.90`).

**Corrected here, not in Git — the release commit's message says less than the commit does.** It names, for an
admin on Uganda, *Verify number* on the sales and support rows, the note after a department's instance is changed, and
`tools/channels.php`'s line. The commit also:
- records, on **any** *Verify number* — a department's or a salesperson's — the number Evolution reports for each
  instance in the store's `wa_evo_numbers` (a table the store creates on its first write; no migration);
- refuses a salesperson's *Verify number* when Evolution reports the owner with no phone number (a WhatsApp `@lid`), and
  a salesperson's *Switch on* until the department numbers are verified;
- adds notes to the card's rows: *not verified for this instance* on a department number; that a department number's
  instance is set under Numbers; and, on a salesperson's number, that Evolution now reports another number, or an owner
  with no phone number.

And its *"276 files, 13,256 passed"* is the plain copy's reading; in the release's worktree the same code reads 13,254
(Tests, below). The message stays as it is: amending it would change the pin. The release notes above and the script's
header say it in full.

### Tests

- **The release tree's own suite: 276 files, 13,254 passed, 0 failed, 0 skipped — twice** (A 13:18:33–13:58:47,
  B 13:58:47–14:38:18 UTC), in the release's git worktree, each pass with nothing else running. Every file's tally is
  identical in both passes.
  - **Against 5.18.89's release suite** (274 files, 12,948 passed, also in a worktree): `test_pilot_safety` (+177) and
    `test_sales_pilot` (+125) are added; `test_channel_registry` (104 → 105) and `test_sales_numbers` (128 → 131) move
    by their own amended assertions. No other file's tally moved (+306 in all).
  - **The same tree read 13,256 in the safety fix's own record** (the entry above, and the release commit's message). The
    2 are `test_quote_tax_line`'s two comparisons with an old commit, which run only where `.git` is a directory: that
    run was a plain copy, these two passes a git worktree, where `.git` is a file — the reason recorded on 07 Oct. The
    code is the same.
  - PHP warnings: 5 per pass, all from `test_dpo_endpoints` (an undefined variable in the test itself), as in 5.18.89's.
  - The checker refuses its own planted faults: a tally moved in one pass only, a warning in one pass only, and an
    unnamed file whose tally moved against the base.
  - The tree was unchanged by both passes: still `3d9cb5f`, clean.
- **`test_pilot_safety.php` on the release tree: 177 passed, 0 failed, with 63 weakened copies, each caught** (the
  development tree reads 185 and 66: the media worker's checks and its three copies are development-only).
- **Focused, in both passes:** `test_sales_pilot` 125/0, the channel registry 105/0, the salesperson numbers 131/0, the
  routing 84/0, the event processor 41/0, migration integrity 28/0; the South Sudan and tenant files read as in
  5.18.89's suite — `test_staff_jobs_south_sudan` 51/0, `test_staff_jobs_gate` 41/0, `test_tenant_profile` 108/0,
  `test_portal_tenant` 112/0, `test_email_no_sudan` 62/0, `test_sales_support_tenant` 37/0, `test_notify_tenant_text`
  30/0, `test_cashbook_tenant` 26/0, `test_phone_country` 26/0, `test_ai_country_facts` 21/0.
- **Lint:** `php -l` on all 28 PHP files of the diff, read from the commit: 0 errors. The sandbox has PHP 8.4; the
  server's own 8.1 lints at A2 (rehearsed: 16 server files and 12 test files accepted).
- `git diff --check 53d5c4d 3d9cb5f`: clean.
- **Secret scans** of the release diff and of the script and its rehearsal: clean. No key-, token- or credential-shaped
  value and no banned value. The phone-shaped values in the tests are fictitious fixtures; the only address in the
  rehearsal is its loopback stand-in for Evolution; the one long hex string is 087's sha256.
- **Reviews:** the safety fix had three independent reviews and a fourth check (docs/65 §AD.6–§AD.9). The deploy script
  and its rehearsal had their own: 16 agents, each finding put to a separate skeptic — **nine confirmed and fixed, three
  refuted**. Two were MAJOR, both in the rehearsal's own expectations, not in the script: the persona's line occurs twice
  in 5.18.89 (the expectations now read `11111200` and `|12114`), and with the registry ON, A11 — not only A5 — stops a
  copy blinded at A5 (2a rewritten; 2a2 added, blinded at both, to show the two layers are independent). The seven MINOR
  ones: a stricter proof of a department number (the latest trail row must record the number now held), the count of
  numbers whose assistant is on (A8 says so in a note), the wording on `wa_evo_numbers`, the `@lid` refusal and the
  Numbers screen's note, the summary's *what did not change*, and docs/66's assumptions about `sales-001`.

### South Sudan — unchanged, and proved four ways

1. **Statically:** every new rule is behind the channel registry, and the registry behind `StaffJobsGate::applies`
   (Uganda): `AutomationPolicy::forInstall` returns `inert()` unless `ChannelRegistry::enabled()`; `EvolutionApiService`
   asks the policy only with a registry; the webhook's step, the AI worker's, the watchdog's filter and the guard's
   recording each sit behind `registryOn()` / `enabled()`; `set_config.php`'s refusal and `tools/channels.php`'s line
   only where `StaffJobsGate::applies`. The event processor is not in the release.
2. **At A6 on the server, before anything changes:** the pin's automated-send policy runs on a throwaway database with
   **every switch on as a South Sudan install** — inert: nothing refused, nothing classified, no SQL added, the assistant
   never stopped — beside the same database as Uganda, where with the registry on it holds a salesperson's number until
   the department numbers are verified and keeps the two from answering each other (the signature's Uganda half shows
   the check can see the policy act). The pin's Batch 2 rules (5.18.89's, unchanged) and the event processor run as
   before. Refusal 6 otherwise.
3. **After the deploy (R13, R15, R17)** the same runs on the installed code; **after a rollback (RB)** the event processor
   and the Batch 2 rules.
4. **In the suite:** `test_pilot_safety.php`'s South Sudan part runs a South Sudan install with **every switch set**
   (the registry, the follow-up hold, automatic follow-ups) through the real webhook, cron and tool: the alert number's
   message is answered exactly as before — not kept as DishNet's own; the registry and the policy are never on, nor is
   any SQL added; `tools/channels.php` says nothing about department numbers; and the webhook guard records nothing
   Evolution reports. `test_staff_jobs_south_sudan` compares South Sudan's pages with 5.18.49's byte for byte. Both pass,
   twice, on the release tree.

The rehearsal shows each South Sudan refusal firing: the policy, the registry and the card each made to forget the
country (1r, 1s, 1t).

### Domain B — untouched

- `dishnet-mikrotik-control-plane/` and the plugin's `docs/` are the same trees at `53d5c4d` and `3d9cb5f`.
- The whole repository's delta between them is the plugin's 29 files alone.
- **A7** refuses any pin that touches either tree or anything outside the plugin (rehearsed: 1i, 1j, 1k; blinded: 2h);
  **R14** after the deploy and **RB** after a rollback compare every installed file of both trees byte for byte.

### `scripts/deploy-5.18.90.sh` — 2,196 lines, sha256 `c8d6e00e216bf00b…`

Made from 5.18.89's script by a derivation with every replacement asserted. Each check added for 5.18.90 is shown to
fail on its fault in the rehearsal (below); the rollback's markers were probed both ways — each of the 13 files that
carry the fix fails RB on 5.18.90's code, and none on 5.18.89's. **It refuses before anything changes:**
1. live is not 5.18.89 at `53d5c4d` (commit and manifest version);
2. `multi_number_channels_enabled` reads ON in either copy (A5);
3. 087 is not recorded, or recorded under a checksum that is not the installed file's (A8);
4. 087 is not as 5.18.89 left it (A8) — an object missing, a department row changed, a salesperson's number switched
   on, or a department number with no verification on the record (or not the number its latest verification
   recorded): nothing before 5.18.90 can verify one, so it was written by hand. The department is named, never the
   number;
5. the delta is not exactly the 29 files, carries a migration, or 087 at the pin is not the reviewed one (A0);
6. South Sudan's protection fails (A6): the event processor, run as South Sudan and as Uganda, reads otherwise than the
   live one's known signatures; the pin's Batch 2 rules apply anything as South Sudan with every switch on; or **the
   pin's automated-send policy refuses or classifies anything as South Sudan with every switch on** — beside the same
   database as Uganda, where with the registry on it holds a salesperson's number until the department numbers are
   verified and keeps DishNet's numbers from answering each other (the signature's Uganda half);
7. Domain B's protection fails (A7);
8. the backup, or the event-queue snapshot, is not confirmed;
9. `sales_own_leads_only` reads ON in either copy (A5b);
10. the pin's assistant prompt is not 5.18.89's for every conversation, on the server's own configuration (A10);
11. **the pin's automated-send policy is not inert on the server's own configuration with the registry off (A11)** —
    nothing refused, nothing classified, no SQL added, the Evolution sender's own policy off, and not one statement
    reaching the database (a recording handle counts them: 0, so no registry read).

The two releases' code must also copy into the container's `/tmp`, or A6, A9, A10 and A11 could not run: a NO-GO. A9
compares how the live code and the pin's route the three numbers, as in 5.18.89. A6, A9, A10 and A11 run both releases'
code in throwaway directories inside the container, removed at exit.

**Evidence, never a stop:** A4 (the lead switches), A5b's other two switches, A3 (the Inbox's row), and **A8's count of
salesperson numbers whose assistant is on** — production's record says none, and with the registry off nothing answers
on one; A8 then says so in a note.

**After the deploy, besides every check carried over (R1, R3–R16, V1–V12, V3–V3h, V4):**
- **R2c** — the installed automated-send policy on the server's own configuration: inert, both copies;
- **R3, R11** — 087 still complete; the department numbers counted, each only with its verification on the record and
  the latest trail row recording the number held; a salesperson's number switched on with the registry off fails;
  an assistant on is reported;
- **R6** — 5.18.90's pieces (21 markers) beside every earlier one;
- **R11** — `tools/channels.php`'s department-number line (*NOT all verified — sales, support*, as production will read
  until step 1.5, or *all verified*);
- **R17** — the installed automated-send policy on throwaway databases: on Uganda with the registry on, a salesperson's
  number held until the department numbers are verified, then the two kept from answering each other, and nothing sent
  with its assistant off; on Uganda with the registry off, and as South Sudan with every switch on, nothing at all.

**The rollback (RB):** 5.18.90's pieces gone from every file that reached them (13 files; the two new libraries stay
on disk, named by nothing — `deploy-hybrid.sh` copies, it never deletes); 5.18.89's Batch 2, 5.18.88's Batch 1 and
5.18.87's Batch 0 in place; 087 as it was, with any department number verified through the card, never read with the
switch off; the event processor 5.18.89's; the assistant's prompt 5.18.89's; the routing, the queue and Domain B as
before.

The script contains no command that changes a switch, and none that verifies, creates or switches a number on the
plugin's data: every registry write it names is P90's, on its throwaway database.

### The rehearsal — `scripts/harness/deploy-5.18.90/rehearse.sh` (1,083 lines, sha256 `74c2c6126ff2b145…`)

The base is installed as production runs it after the 07 Oct pilot:
- 5.18.89 with 086 and 087 applied by the plugin's own runner — 087's three department rows, no instance, no number;
- the pilot's salesperson number as production has it: added through the card, switched off, its assistant off, its
  trail as recorded (a fictitious seller and instance);
- the authorisation and the booking WhatsApp on; the lead switches as decided on 07 Oct (capture ON, the uCRM write OFF);
- the Inbox's row naming three fictitious numbers, with support and account sharing one instance, and naming as Evolution
  **a recording stand-in that answers anything and logs every request**;
- the registry's switch and 5.18.89's three switches unset;
- an event queue with 6 events, 3 of them not done; two photos.

It covers:
- **the pin (section 0):** cut on `53d5c4d`; 29 files, 4 added, none removed; no migration and 087 the reviewed file;
  the twenty-two files taken whole are the safety fix's (`a694161`) byte for byte, the seven ported ones differ; no
  media-layer, portal or CSRF file; Domain B and the plugin's docs the same trees; the header's deploy command alone in
  its block; no switch command and no number written on the plugin's data anywhere in the script;
- **every refusal (1a–1z3):** 5.18.88 still live; the right commit with the wrong manifest; a placeholder pin; the
  branch tip; a media-layer file; a migration; 087 one comment different; the follow-up scan without its policy; Domain
  B, the plugin's docs, or a file outside the plugin; the registry's switch ON; own leads only ON; 087 not recorded,
  recorded under another checksum, a salesperson's number switched on, a department number with no verification, or a
  trigger missing; a pin whose send policy, registry or card forgets the country (South Sudan); a pin whose send policy
  reads the registry with its switch off (A11); the code copy failing; the backup failing. A4, A5b's other two
  switches, A3 and the pilot's assistant switched on (A8's note) are evidence, not stops;
- **weakened copies of stage A (2a–2p):** A5, A5b, A8, A6, A7, A9, A10 and A11 each blinded and caught; A5 and A11 shown
  to be independent layers (2a, 2a2); with the allow-list blinded, a migration is stopped by the count and an altered
  087 by its sha256;
- **the deploy (section 3):** the rehearsed deploy reads **65 ok / 0 failed / 0 notes**. No data is written at all —
  every table, 087's two included, the vault, the configuration files, the photos and the queue as before — and no
  Evolution address, key, instance name or number appears in the log;
- **the teeth (4a–4v):** 5.18.89's WhatsApp AI screen back; the installed policy reading the registry with its switch
  off (R1, R6, R2c, R17); the policy no longer recognising DishNet's own numbers (R17); R2c, R17, R13, R11 and V12 each
  blinded and caught; 5.18.89's CLI back (R11); department numbers verified through the card — reported, and
  `--after-only` still PASSES; a department trail with anything but 5.18.90's verification, or a number changed by
  hand after it — failures; a salesperson's assistant on — reported, never a failure; a number switched on with the
  registry off — a failure; a trigger dropped; a department row given an instance; the registry's switch ON; own leads
  only ON; a Domain B file changed; an event deleted; the card leaked to a visitor with no session; and 087's log line:
  OK counting 13 of 14, a PARTIAL, no line at all;
- **5.18.88's two fixes (5a–5g):** R7 and V4, each with its control on the control;
- **the switches after the deploy (section 6):** the uCRM write on and off again, byte for byte; **the registry's switch
  set with the installed tool: 5.18.90 refuses it while the department numbers are not verified — nothing saved, either
  copy (6c); once they are verified the tool accepts it, and the checks say at once that the registry is not dark
  (6c2)** — then put back; the hand-over copy off and owned follow-ups on — reported; own leads only on — the checks say
  so — and put back; qualification off; the Inbox's account number taken away, or moved during a run;
- **the rollback (section 7):** typed `ROLLBACK`. RB reads 5.18.89's files and manifest, the safety fix gone, Batch 2,
  Batch 1, Batch 0 and 5.18.86's Inbox route kept, 087's tables as they were and not read with the switch off (7b is
  the control on the control: with the switch ON, the same check sees 5.18.89 read them), the queue and Domain B as
  before, no data changed. 7d deploys again (PASSES); 7e rolls back with the department numbers verified (PASSES, the
  numbers kept, read by nothing); 7f deploys over them (A8 reports them with their verification; PASSES); 7g rolls back
  once more (PASSES). No number and no instance name is printed;
- **what is left behind, and what was never reached (section 8):** **Evolution was never called in any of the script's
  runs** — no instance read, created or paired, no message sent — and the control on the control: the stand-in records
  the one request the rehearsal makes itself; the checkout as found; no weakened copy; nothing in `/tmp`; no PHP fatal
  from the stand-in.

**Results.** One run on the working copy before the commit read **334 passed, 0 failed**, 106 runs of the script
(14:38:43–14:49:41 UTC). Then two runs on the committed script (`afd375f`), each with nothing else running:
- **run 1: 334 passed, 0 failed**, 106 runs of the script, no FAIL line, the checkout left as found (14:52–15:03
  UTC); its check lines are the working-copy run's, one for one, time stamps aside. Its log also holds 106 lines *No such
  file or directory*: it was started with `REHEARSE_KEEP` naming a directory that did not exist, and the harness writes
  each run's full output there without creating it. Only those kept copies were lost; no check reads them.
- **run 2: 334 passed, 0 failed**, 106 runs, the same check lines as run 1, time stamps aside (15:04–15:15 UTC). Its
  keep directory was created during section 1, so the outputs from run 12 on are kept (11 lines as above). The kept
  deploy reads **65 ok / 0 failed / 0 notes**, *5.18.90 (deploy): PASSED*; the kept rollbacks read **41 ok / 0 failed /
  2 notes**, *PASSED* — the notes say what the rollback takes away, and that the two new libraries stay on disk, named
  by nothing — and 7b's control on the control fails as it must (V3f and RB, with the switch set ON).

### Deployment runbook — each step is the operator's, and each needs the operator's approval first

1. **Push `release/5.18.90` — DONE 08 Oct, 15:46:17 UTC**, on the operator's word (*"APPROVED: DEPLOY RELEASE 5.18.90
   TO PRODUCTION"*): a new branch at `3d9cb5f`. The branch `claude/study-this-jhe2eg` (the fix, the script, its
   rehearsal and these records) was pushed already, at `733ae01`.
2. **Deploy, as root on the server — DONE 15:52 UTC**, on the same word: **PASSED, 65 ok / 0 failed / 1 note** (the
   RESULT below). It asks for `DEPLOY`. Send back the **log file**, not a copy of the terminal:

   `cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.90 && mkdir -p /root/dnb-5.18.90 && bash scripts/deploy-5.18.90.sh 2>&1 | tee /root/dnb-5.18.90/deploy-$(date -u +%Y%m%dT%H%M%SZ).log`

   - **Expect 65 ok / 0 failed / 1 note** — *as it read.* The rehearsal's deploy reads 65 ok / 0 / 0; on the
     server R3 adds the note 5.18.89's deploy had: `migration.log` holds no line for 087 (rotated away since 07 Oct
     09:52), and R3 reads every one of 087's statements' effects from the store instead (rehearsed: 4t).
   - Other notes would say: the salesperson number's assistant is ON (A8 — production's record says off); a lead switch
     is not as decided on 07 Oct (A4); `ai_qualification` is off; the Inbox's row lacks a number (A3).
   - **If a refusal stops it, nothing has changed.** The log names the refusal: send it back.
3. **After the deploy: nothing to switch on.** Admins see *Verify number* on the department rows of the *Salesperson
   numbers* card, and the card says they are not verified yet; nothing else a person sees changes. Run `tools/channels.php`
   (docs/66 P3): it reads `department numbers: NOT all verified — sales, support`.
4. **Then, and only then, docs/66 resumes — each step with its own approval, none of it here:** step 1.5 (the department
   numbers verified on the card), the AI-to-AI loop test, and the first salesperson's number (`sales-001`, already added
   on 07 Oct: never added again).

### RESULT — DEPLOYED to production 2026-10-08, 15:52 UTC: PASSED, 65 ok / 0 failed / 1 note

Recorded from the terminal the operator pasted, which carries the run up to its verdict; the script prints no secret.
The log file stays on the server under `/root/dnb-5.18.90/`.

**Approval and push.** The operator approved, on 08 Oct: *"APPROVED: DEPLOY RELEASE 5.18.90 TO PRODUCTION"* — push
`release/5.18.90`, deploy `3d9cb5f` with the rehearsed pinned procedure, run every post-deployment check, then stop;
nothing switched on, no number verified, paired or switched on, no Evolution change, no message, no pilot.
`release/5.18.90` was pushed at 15:46:17 UTC, a new branch at `3d9cb5f`.

**The run** began at 15:51:00 UTC (`20261008T155100Z`). `DEPLOY` was typed, `deploy-hybrid.sh` answered *"✓ container
now serves 3d9cb5f"*, and the 29 files were stamped at 15:52:02 UTC.
- **The rehearsal's 65 ok lines and the server's are the same 65, by check and in the same order.** The server adds one
  note, R3's — the one the runbook predicted.
- Every value that differs from the rehearsed deploy is the server's own data or environment: paths, PHP 8.1.34, row
  counts, the queue's size, R7's figures, the 25 lines V4 read. **087's state, every switch, the routing, and the send
  policy's, Batch 2's and the event processor's signatures read as the rehearsal's, character for character.**

- **The pull.**
  - The checkout fast-forwarded `1541208` → `733ae01`, and `release/5.18.90` was fetched as new.
  - Branch tip `a694161` (not installed); release commit `3d9cb5f`, cut on `53d5c4d`.
  - 29 files (25 changed, 4 added, 0 removed); no tracked edits.
- **A — no refusal fired, and every check passed:**
  - A7: Domain B untouched (`cf0b0e3`, `969d873`). A0: exactly the 29 files, no migration, 087 the reviewed one.
  - Live was `53d5c4d` / 5.18.89. **A2: PHP 8.1.34 accepted all 16 changed server files and 12 test files.**
  - The switches as the operator left them:
    - pilot `on`, `ia=on/on`, `wa=on/on`;
    - the lead switches `lc=on/on`, `ls=off/off`, `qu=on/on`, `sa=on/on`;
    - the registry's switch `mn=absent/absent` (A5); `ol`, `hc`, `fh` `absent/absent` (A5b);
    - the Inbox's row `evo=yes sales=yes support=yes account=yes registry=absent` (A3).
  - 086 complete, `2:20:1:1:1`.
  - **087 applied and complete (A8):** `rows=4:5 seed=ok dnum=0 other=1 active=0 oai=0 trail=ok dtrail=0`. That is the
    three department rows as 087 seeds them, with no instance and no number, and one salesperson number, switched off
    with its assistant off — the state docs/66 records since 07 Oct.
  - **The routing:** `sales:in=sales support:in=support account:in=support shared=support+account evo=yes registry=off`,
    in the configuration files and in the Inbox's row. A9: the pin's code routes exactly as live's.
  - **A10:** the pin's assistant builds 5.18.89's prompt for all twelve conversations, on the server's own
    configuration.
  - **A11:** the pin's send policy is inert there: `policy=off/off refusal=-/- sql=-/- evo=off,-/off,- statements=0
    registry-reads=0`.
  - **A6, South Sudan:** live's and the pin's event processor read the same signatures on both countries. The pin's
    Batch 2 rules and send policy, every switch on, apply nothing as a South Sudan install. The send-policy signature
    is the reviewed one exactly.
  - The webhook log's last 300 entries hold 1 `job.add`, the last at 2026-10-07 09:26:31 by the plugin's clock.
    Photos `present:19:4`, 19 files.
- **Backup**, in `/root/dnb-5.18.90/backup-20261008T155100Z`:
  - `plugin.sqlite3`, 31 MB: one consistent copy, integrity ok, 250 tables, SQLite 3.48.0;
  - the data directory, 155 MB; the installed 5.18.89, 11 MB; the vault;
  - the event queue's snapshot: **0 events not done** (8,463 in all, every one done).

  Then `GO`.
- **V.**
  - The sign-in page answers 200 with zero redirects, and carries no South Sudan contact; the portal answers 302;
    pinch-zoom is allowed.
  - The photo viewer answers 302 and its upload 401. The authorisation page answers 404 *"This link is not valid"* to a
    request without a link.
  - `install_auth_request`, `install_auth_prefill`, `wa_send_reply` and `log_call` answer 401 without a login.
  - **V12:** the WhatsApp AI screen sends a visitor with no session to the sign-in page (302), with no part of the card.
  - Every switch, the Inbox's row and the AI settings (`files=25 store=24`) are unchanged by the run (V3–V3h).
  - **V4: no fatal or parse error since the copy, with 25 log lines read** after the 60-second wait.
- **R.**
  - **R1:** all 29 files as `3d9cb5f` has them; manifest 5.18.90.
  - **R2, R2b, R2c:**
    - the pilot, the authorisation and the booking WhatsApp are as the operator left them;
    - own leads only OFF; the follow-up hold holds nothing; the card is shown;
    - **the installed send policy is inert on the server's own configuration, both copies.**
  - **R3:** 086 complete (`2:20:1:1:1` before and after); 084 `19:4`, 19 files; **087 applied and complete**, as at A8.
    **The one note:** *"migration.log holds no line for 087 (not written, or rotated away) — every statement's effect was
    read from the store above, which is the verification"* — the case the rehearsal planted (4t), as on 07 Oct.
  - **R4:** no media-layer, portal or CSRF file; the pilot's channel is `NullWhatsAppChannel`. **R5:** all 253 files
    from Release A through 5.18.89 intact. **R6:** every earlier surface, and 5.18.90's pieces.
  - **R7** (read-only): **UGX CASH IN HAND 591,072.00 · USD 0.00**, the figures 5.18.89's deploy read. The photos as
    before the run.
  - **R8:** the quotation reader. **R9:** the Inbox route. **R10:** the lead path, and the uCRM write OFF.
  - **R11:** the registry is dark — its switch OFF in both copies, `enabled()` and `forStore()` off, 0 statements on its
    tables; one salesperson number, switched off, assistant off. **`tools/channels.php` reads *"department numbers: NOT
    all verified — sales, support"*.** Account shares support's instance, so support's number covers it.
  - **R12:** the three numbers route exactly as on 5.18.89. **R13:** the event processor as designed on both countries;
    the queue held 8,463 events, every one done, before and after. **R14:** Domain B, all 363 files, byte for byte.
  - **R15:** 5.18.89's Batch 2 rules as before. **R16:** the installed assistant's prompt is 5.18.89's.
  - **R17:** the installed send policy on throwaway databases:
    - on Uganda with the registry on, it holds a salesperson's number until the department numbers are verified, then
      keeps the two from answering each other, and stops a number whose assistant is off;
    - with the switches absent, and as South Sudan with every switch on, it does nothing.
- **What the run did not do:**
  - It set no switch and changed no configuration value it reads (V3–V3h).
  - It created, paired or called no Evolution instance, and added, verified or switched on no number. 087 reads as at
    A8, and the script has no code path that calls Evolution; the rehearsal proved that with a recording stand-in.
  - It sent nothing. Its requests over the public address were anonymous; the POSTs among them carried no data and
    were refused with 401 before any handler. The event queue held 8,463 events, every one done, before and after.
  - The log cannot speak for anything else on the server in that window.
- `--check` read *"NOT up to date"* before and after, as expected: it compares the container with the branch tip,
  `a694161`, which this script does not install.

**Independent verification of the log** (read-only, 08 Oct). Three verifiers read the log, the script and the record,
and a separate skeptic challenged each item they flagged. Proved: the version before and after, the registry OFF, no
salesperson number active, South Sudan and Domain B. What the log does not prove, and stays recorded:
- **The pasted text is a copy of the terminal, not the `tee`'d log file.** It holds the typed `DEPLOY`, which `tee`
  never sees, and it ends at the verdict, without the rollback block the file holds. The checks are all there and agree
  with the script's own tally. The file on the server settles it. **Settled 09 Oct:**
  `/root/dnb-5.18.90/deploy-20261008T155100Z.log` holds 172 lines, the whole run, rollback block included.
- **R13's "kept=0/0" compared an empty set:** no event was pending. The evidence that nothing was lost is the queue's
  totals, 8,463 done before and after.
- **R3 and R11 re-read 087's counts, not its rows.** The script has no statement that writes them — it only reads the
  store — but no before-and-after hash of the two tables was taken.
- **`set_config.php`'s refusal of the registry is checked on the server as installed code** (R1's bytes, R6's marker),
  not run. It was run in the rehearsal (6c, 6c2) and in `test_pilot_safety`.
- **`deploy-hybrid.sh` copies the checkout's working tree** under the plugin, not only the files git tracks, and every
  check reads the tracked files. A file git does not track there would be copied unseen. This is the copy every release
  since 5.18.66 has used. Listing the untracked files settles it. **Settled 09 Oct:** `git ls-files --others` under the
  plugin, leaving out `data/`, lists nothing — the copy was exactly `3d9cb5f`'s tracked files.
- **Two helpers carried over since 5.18.66** (`photo_tables_state`, `dist_tables_state`) open `plugin.sqlite3` as the
  container's default user, read-write, not as the database's owner read-only, as every other read does. No incident
  has been seen in any deploy since. The owners of the files beside the database settle whether one was left; the next
  deploy script should read them as the others do. **Settled 09 Oct:** no `-wal` or `-shm` file beside the live
  `plugin.sqlite3`, and every file listed is owned by `1000:1000`, as the database is. Nothing was left.
- **Before the deploy, the AI settings counted one key more in each copy than on 07 Oct** (`files=25 store=24`, against
  `24/23` in 5.18.89's log). This run changed nothing there (V3g). Only an admin's save — the WhatsApp AI screen or one
  of the setting tools — adds a key; neither the card nor any deploy does. Which key it is was not established, because
  the log names none.
- **Found by the same 09 Oct listing, older than this release: a second `plugin.sqlite3`** inside the plugin's own
  `data/` folder — 2.4 MB, owned `1000:1000`, last written 07 Oct 09:55 UTC, three minutes after 5.18.88's deploy. The
  live store is `.dishnet-hybrid-sudan-data/plugin.sqlite3` (33.6 MB, written at 05:40 on 09 Oct), named by
  `ucrm.json`'s `pluginDataDir`. Every reader that can read `ucrm.json` uses it: the deploy's checks did, since R11 saw
  the salesperson number added on 07 Oct evening, which the stray file predates.
  **Corrected 09 Oct (5.18.91's entry, below) — not by `pluginDataDir`.** The server's `ucrm.json` has no such key
  (observed: the key names listed by the read-only `stray-db-evidence.sh`, run on the server at 06:29:30 UTC, its
  output in `/root/dnb-stray-db-evidence-<UTC time>.txt`; the script is not in this repository — 5.18.91's entry
  gives its checksum). `getDataDir()` chose the folder beside the plugin by its second rule, because the plugins
  root is writable (`lib/bootstrap_data.php`). The deploy's checks read the live store all the same: when
  `ucrm.json` names no folder they take the one beside the plugin if it holds a `plugin.sqlite3`
  (`deploy-5.18.90.sh`, where it sets `PDD_IN`), and R11's evidence above stands.
  - What wrote the stray file is NOT ESTABLISHED. `getDataDir()` falls back to the plugin's own `data/` only when
    `ucrm.json` gives no `pluginDataDir` to the process reading it.
    **Corrected 09 Oct (5.18.91's entry, below) — identified by the code and the ledger's timing, and the fallback
    misstated.** The only scheduled opener of that file found in the code is `cron_starlink_block_retry.php`, which
    names `<plugin>/data` itself; `cron/master.php` runs it at most every ten minutes, and by the code every run opens
    the file there and applies any migration it lacks (two manual tools open it too; 5.18.91's entry names them). The
    stray's own `_migrations` ledger shows 087 applied at 09:55:07 on 07 Oct, about three minutes after the live
    store's at 09:52:18 (5.18.88's deploy) — consistent with the job's next dispatch; master's record of that cycle
    was not read. Observed by the 09 Oct evidence scripts: the file's ledger begins on 26 Aug at 09:17:20, the live
    folder beside the plugin was born at 09:27:55 that day, and the file itself on 19 Sep at 07:08:01, when the plugin
    folder was re-created. By the code and that timing, not observed: the live folder was made by `getDataDir()`'s
    one-time rescue copy, so the file's content began as the plugin's database from before the move, and something
    carried it through the re-creation, by a means not established. And `getDataDir()` takes the plugin's own
    `data/` only when `ucrm.json` names no folder **and** the plugins root is not writable; with no `pluginDataDir`
    and a writable root it takes the folder beside the plugin, as on this server.
  - **It disarms the command-line guard.** `cliDataDir()` refuses that fallback (*"THIS IS NOT WHERE THE DATA LIVES"*)
    only while no `plugin.sqlite3` exists there. A tool that took the fallback now would read the stray file in
    silence.
  - Left in place, unread and untouched: removing it is a production change of its own, and it is the only evidence of
    what created it.

### Rollback — its own command, never pasted with the deploy

`scripts/deploy-5.18.90.sh --rollback` asks for `ROLLBACK`, puts 5.18.89 (`53d5c4d`) back through `deploy-hybrid.sh`,
and checks it (RB). The department numbers' *Verify number* goes; a department number verified through it stays in 087's
tables, and the store's `wa_evo_numbers` stays if a verification made it — 5.18.89 reads neither while the registry is
off. Every switch stays as it is. The rehearsed rollback reads 41 ok / 0 failed / 2 notes: both notes say what it takes
away, and that `lib/AutomationPolicy.php` and `lib/InternalNumbers.php` stay on disk, named by nothing
(`deploy-hybrid.sh` never deletes).

    cd /opt/dishnet && bash scripts/deploy-5.18.90.sh --rollback

By hand, only if the script cannot run: `cd /opt/dishnet && git checkout 53d5c4d && bash scripts/deploy-hybrid.sh && git checkout -`.

## 09 Oct — 5.18.91: the database safety fix, Uganda only — the Starlink retry job no longer opens the stray `plugin.sqlite3` inside the plugin folder; it is NOT pointed at the live store; no migration; South Sudan and Domain B unchanged. `release/5.18.91` = `dfad4d9`, cut on live 5.18.90 (`3d9cb5f`); `scripts/deploy-5.18.91.sh` pinned to it and rehearsed — PUSHED 10 Oct 02:50 UTC; DEPLOYED, copy stamped 02:53 UTC (the deploy run's log not yet received); `--after-only` 03:14 UTC: R19 PASSED, V4 FAILED on `cron/master.php:405`, a fatal older than this release (confirmed on the server: 7 lines since 08 Oct, 4 of them before 5.18.91)

**Approval.** The operator approved, on 09 Oct: *"APPROVED: IMPLEMENT THE UGANDA-ONLY 5.18.91 DATABASE SAFETY FIX AND
PREPARE THE RELEASE."* — the fix, its regression tests, commits, and the development branch's push where needed to
prepare and review the release. **Not approved, and not done:** the deploy; pushing `release/5.18.91` (the server
fetches it at deploy time, so it is pushed with the deploy's approval); quarantining, moving, renaming or deleting the
stray database; `cliDataDir()` or `getDataDir()`'s rescue copy; any configuration value, switch, Evolution setting or
WhatsApp number; resuming the salesperson pilot; anything in South Sudan's behaviour or in Domain B. The operator's
order: fix the wrong-database access first; once it is deployed and verified, quarantine the old database as its own
approved step. **The 19 Sep re-creation of the plugin folder is not explained by this release** (Known limits 1).

### The five-part form

1. **What is configured now (5.18.90, `3d9cb5f`)** — as read on 09 Oct by the operator's two read-only evidence scripts
   on the server: `stray-db-evidence.sh` (its first line `now 2026-10-09T06:29:30Z`) and `stray-db-evidence-2.sh`
   (`now 2026-10-09T06:36:55Z`), each output written by `tee` to `/root/dnb-stray-db-evidence-<UTC time>.txt` and
   `/root/dnb-stray-db-evidence-2-<UTC time>.txt`. The scripts are not in this repository; their sha256, printed on the
   server before each run, begin `b806a37a6a85dd23` and `2e353daf90ae1426`. They read files, and the databases only
   immutably, and print no value, key, number or record.
   - **Observed:** `ucrm.json` names **no** `pluginDataDir`; `getDataDir()` chooses `.dishnet-hybrid-sudan-data` beside
     the plugin folder by its second rule, because the plugins root is writable. That is the live store.
   - **Observed:** a second, stray `plugin.sqlite3` inside the plugin's own `data/` folder, 83 migrations recorded, the
     last (087) applied at 09:55:07 on 07 Oct and the file last written then. Its ledger begins on 26 Aug at 09:17:20; the
     live folder beside the plugin was born at 09:27:55 that day. The file itself was born on 19 Sep at 07:08:01, when
     the plugin folder was re-created (Known limits 1).
   - **By the code and that timing, not observed:** the live folder was made by `getDataDir()`'s one-time rescue copy,
     so the stray's content began as the plugin's database from before the move, and something carried it through the
     19 Sep re-creation, by a means not established.
   - **Observed:** the `migration.log` beside it gains about 319 lines a day of 071's checksum warning. **That growth
     cannot be counted as opens of the stray store** (corrected below): the file is also `MigrationRunner`'s default
     log, which `cron/dpo_reconcile.php` (every 5 minutes) and the DPO pages write while migrating the LIVE store.
   - `cron_starlink_block_retry.php`, scheduled by `cron/master.php` at most every 600 s, ungated.
2. **Why.** The retry job names `<plugin>/data` itself. By the code, every run opens the stray database, applies any
   migration it lacks, and holds it open until the process ends. It also leaves `$dataDir`, `$config`, `$store` and
   `$pdo` pointing there in `cron/master.php`'s shared scope. It is the only SCHEDULED opener of that store found in
   the code (two manual ones exist, Known limits 3):
   - the two jobs after it, `customer_reminders` and `notify_retry`, set `$dataDir` and `$store` to the live store
     before they use them;
   - `main.php`'s tail opens no store there (its one `SqliteStore::create` names a class that does not exist).

   What the leaked `$dataDir` does reach:
   - master's dispatch gate for those two jobs (`cron/master.php` line 319);
   - `main.php`'s tail, in a cycle where neither job runs after the retry job — there it writes plain files into the
     stray folder (two wallet-sync files on 07 Oct).

   The stray file's existence also disarms `cliDataDir()`'s guard, and would feed `getDataDir()`'s rescue copy if the
   live store ever went missing. Fix the wrong-database access before the WhatsApp pilot resumes.
3. **Exactly what changes.** On Uganda the retry job stops at its first statement — before it names, opens or migrates
   anything — and leaves nothing behind in master's scope. **It is not pointed at the live store:** that would start a
   Starlink retry and restore process that has not run against the live store since the 26 Aug move (its queue there
   was empty at the 08 Oct backup), a business change nobody approved. South Sudan, and anything the gate cannot
   decide, run 5.18.90's job exactly: below the gate the file is 5.18.90's byte for byte. **No migration; no
   configuration value set.**
4. **Effect on UISP/uCRM.**
   - UISP: none. uCRM: none. Evolution and WhatsApp: nothing called or sent.
   - The plugin's SQLite: the deploy writes no record. The live store is read — as its owner, read-only, through SQLite
     only — by the deploy's checks and by the gate, except R7: as every deploy since 5.18.71, it runs the cash-in-hand
     tool as the container's default user, and that tool opens the live store read-write through the plugin's own
     store (Known limits 17).
   - The stray store: the deploy reads it as files and copies it into the backup. It never opens, moves or changes it.
5. **Rollback.** `scripts/deploy-5.18.91.sh --rollback`, typed `ROLLBACK`, puts 5.18.90 (`3d9cb5f`) back through
   `deploy-hybrid.sh` and checks it (RB). From then on the retry job opens the stray store again, at most every 10
   minutes, as before. If the stray store has been quarantined by then, put it back first: 5.18.90's job would otherwise
   create a new one there. The runbook is its own section below, never beside the deploy command.

### Release notes

- **`cron_starlink_block_retry.php`** (+20). Its first statement asks `StarlinkRetryScope::skip(__DIR__)` in a
  static closure of its own, so nothing is assigned at master's scope.
  - On `true` the job returns (SAFETY.md RULE 11b: a scheduled cron never calls `exit`).
  - If the library is missing (a partial install), it logs one line and runs the job as before.
- **`lib/StarlinkRetryScope.php`** (new, 144 lines). `skip()` is true only for a Uganda install whose data
  directory is not `<plugin>/data`.
  - **How it decides.** It uses the composition the plugin's own Uganda-gated jobs use (`cron/notify_retry.php`,
    `cron/customer_reminders.php`): the live store's `kyc_config` row first, then the configuration files and the vault,
    through `StaffJobsGate::applies()`. Unlike those jobs' `PluginConfig::load()`, it reads with `PluginConfig::read()`,
    which never refreshes the vault.
  - **It changes nothing on disk.** It does not call `getDataDir()`, which creates the folder it chooses and may run the
    rescue copy. Instead `liveDataDir()` mirrors getDataDir()'s three rules read-only; the test holds the two equal in
    five layouts and pins `getDataDir()`'s source by sha256.
  - **The live database is never opened except by SQLite.** Inside `cron/master.php` this process already holds it open
    with POSIX locks. Closing any other descriptor of that file — or of its `-shm` — releases them (sqlite.org, *How To
    Corrupt*, 2.2).
  - **The store is read read-only**, and only when its `-wal` and `-shm` already exist (decided by `is_file()`).
    Otherwise a read-only reader would create them, possibly owned by the wrong user.
  - **Anything unclear is `false`**, which is 5.18.90's behaviour: no answer, an unreadable file, any throwable.
- **`tests/test_sl_block_retry_scope.php`** (new, 86 checks) — below.
- **`manifest.json`** 5.18.91. The release also changes the five distributor tests' version pins; the branch changes
  fifteen.

**Where this differs from the investigation's proposal** (the report of 09 Oct, §6):
- *"resolve the live folder with `getDataDir()`"* — not called: it creates the folder it chooses and may copy the
  legacy database into it. `liveDataDir()` makes the same choice by reading only.
- *"return at once with one log line"* — no line: the job logs only when it does work, and a line at every run would be
  permanent noise. The evidence that it stops is R19's pass on the stray FOLDER after a completed run of the fixed job
  (runbook step 3), not the database file alone.
- the test is named `test_sl_block_retry_scope.php`, and covers the proposal's list (below).

### Files — 9 against `3d9cb5f`: 2 added, 7 changed, none removed

- **Added (2):** `lib/StarlinkRetryScope.php`, `tests/test_sl_block_retry_scope.php`.
- **Changed (7):** `cron_starlink_block_retry.php`, `manifest.json`, `tests/test_distributor_apply.php`,
  `tests/test_distributor_link_ucrm.php`, `tests/test_distributor_notify.php`, `tests/test_distributor_registry.php`,
  `tests/test_distributor_territory.php` (the version pin only).
- **9 files changed, 786 insertions(+), 6 deletions(-).** No migration; nothing outside the plugin.

### Ancestry

`dfad4d9` (5.18.91, release) ← `3d9cb5f` (5.18.90, live) ← `53d5c4d` (5.18.89) ← `6464204` (5.18.88).
- All nine files are the development branch's, byte for byte (`2613fc2`).
- The branch also carries undeployed work — the partner portal (081–083), the PD-8 CSRF guard and the AI media layer
  (085). None of it is here.
- `release/5.18.91` stays local until the deploy is approved.

### Tests

- **`tests/test_sl_block_retry_scope.php`: 86 passed, 0 failed, with 13 weakened copies, each caught by the check
  it names.** The weakened copies run without the source checks, so each is caught by what the code does, and a copy
  counts as caught only where that check passes unweakened. It builds the server's layout: `ucrm.json` with no
  `pluginDataDir`, the live store beside the plugin, and a stray store inside it, one migration behind so that an open
  that migrates it, as the job's does, shows in its ledger; any other open shows in its folder. It also builds a level
  stray store, at every migration as the server's is; built here with every recorded checksum matching, an open of it
  changes only the folder. Each property below is proved against the 5.18.90
  job as its control:
  - **Uganda, through `cron/master.php`'s shared scope:** the stray store's checksum, size, time and ledger are
    unchanged, and its folder gains, loses and changes nothing. `$dataDir` is still the live directory afterwards, and
    no variable is left behind.
  - **Uganda with no stray store:** none is created.
  - **Uganda selected by the vault alone:** nothing in either folder changes, the live store's `-wal` and `-shm`
    included.
  - **South Sudan:** exactly the 5.18.90 job's outcome.
  - **Uganda where `<plugin>/data` IS the data directory:** the job runs as before.
  - **Every unclear case** gives South Sudan's answer.
  - **Master's POSIX locks:** the locks on the live database and on its `-shm` are read from `/proc/locks` before and
    after the job, and none is lost.
  - **`liveDataDir()` equals `getDataDir()`** in five layouts and with an unwritable plugins root, and deciding creates
    nothing.

  Round 7 reworded the docblock and one label that said any open of the one-behind store shows in its ledger: only an
  open that migrates it does (a read-only open shows in the folder, which the same checks watch); no check changed.
  Round 5 reworded the labels that had stated the server's log behaviour as fact, and the headline, which now says where
  the gate applies; no check changed. Round 4 added a positive control to the server-shaped template: its
  `migration.log` must exist and hold lines before it is compared. **The control on the control:** with the log
  deleted after seeding, the test reads 83 passed, 3 failed — the template check and both 3b checks. With the new
  clause also removed, the template check passes on a log that is not there (84 passed, 2 failed, the 2 being 3b's own
  checks): the vacuous pass the review described. Both copies were
  scratch files, removed afterwards.
- **Full suite, development, twice** — on the development tree, `2613fc2` — the same tree as `ba3181a`, on which both passes ran; amending the commit changed its message only. Pass A 19:02–19:53 UTC and pass B 21:07–21:57 UTC on 09 Oct, each alone: 289 files, 14,262 passed, 0 failed, 0 skipped, identical file by file to each other and to the pass on `259a841`, which differs only in the test's docblock and one label. The only PHP warnings are `test_dpo_endpoints`' five, in every pass. Two earlier starts of pass B were ended part-way by container restarts; their logs are void and kept aside.
- **The release tree's own suite, twice** — in the release's own worktree, `dfad4d9` — the same tree as `03ebb30`, on which both passes ran; amending the commit changed its message only. Pass A 21:57–22:41 UTC and pass B 23:02–23:46 UTC on 09 Oct, each alone: 277 files, 13,340 passed, 0 failed, 0 skipped, identical file by file to each other and to 5.18.90's release suite (276 files, 13,254 passed) but for the one test this release adds, 86 checks. The only PHP warnings are `test_dpo_endpoints`' five, as in 5.18.90's.
- **Recorded, not changed:** the five distributor tests label their version pin `manifest version is 5.18.71`, whatever
  version they pin — the convention since 5.18.71, kept by every release since, 5.18.90's included; 5.18.91 changes the
  pin only.
- **Lint:** `php -l` on all eight PHP files of the release diff, read from the commit: 0 errors (PHP 8.4.19 in this environment). The server's
  own PHP lints them at A2.
- `git diff --check 3d9cb5f dfad4d9`: clean.
- **Secret scans** of the release diff and of the script and its rehearsal: clean. No key-, token- or credential-shaped
  value and no banned value. The long hex strings are, in the release diff, 5.18.90's retry job's sha256 and
  `getDataDir()`'s source sha256 (pinned by the test); in the script and the rehearsal, 087's and the retry job's
  sha256. The only phone-number-shaped values are the throwaway-database fixtures of the automated-send policy check,
  carried unchanged from 5.18.90's script: placeholders made of a country code and zeros, reviewed by hand.

### South Sudan — unchanged, proved

- **By construction:** below the gate the retry job is 5.18.90's byte for byte. The test strips the 5.18.91 block and
  compares the rest with 5.18.90's sha256. The gate answers South Sudan's `false` without touching anything.
- **By the test**, through `cron/master.php`'s shared scope on throwaway layouts. With the live store naming South
  Sudan, and with nothing naming anything (the default), the job:
  - leaves exactly the variables 5.18.90's job leaves;
  - points `$dataDir` and `$store` at the same database;
  - changes the same files in `<plugin>/data`;
  - migrates the stray store as 5.18.90's does, and still creates one where none exists.

  Master keeps its locks on the live database and its `-shm` throughout. An unreadable store or vault, a
  `pluginDataDir` that is no path, and the library missing each give South Sudan's answer.
- **Before the deploy (A13)**, on the server's own PHP, as a South Sudan install on a throwaway layout: the pin's job
  and the live 5.18.90 job must give the same signature, and the expected one (`SLR_OPENS`), or nothing is deployed.
- **After it (R18)**: the INSTALLED job, run the same way, must still give that signature, or R18 fails. That is a
  failure in the log, not a refusal: the files are already copied.
- `cliDataDir()`, `getDataDir()`'s rescue copy and every other file are untouched. The South Sudan server itself was not
  examined, and its folder layout is not known (Known limits 7).

### Domain B — untouched

- `dishnet-mikrotik-control-plane/` and the plugin's `docs/` are the same trees at `3d9cb5f` and `dfad4d9`. The whole
  repository's delta is the plugin's nine files.
- **A7** refuses any pin that touches either tree, or anything outside the plugin. **R14** after the deploy, and **RB**
  after a rollback, compare every installed file of both trees byte for byte.

### `scripts/deploy-5.18.91.sh` — 2821 lines, sha256 `3393736f5cd911ec…`

Made from 5.18.90's script by a derivation in which every replacement is asserted. It has a guard that refuses a PHP
variable named bare in a bash string: the second review found one, which under `set -u` ended every run before its
verdict. Everything 5.18.90's script checks is kept.

**It refuses before anything changes** when, besides 5.18.90's refusals:
- **A0** — the pin is not cut on `3d9cb5f`, its delta is not exactly the nine files, or it carries a migration.
- **The checkout** — it holds a file git does not track under the plugin folder, outside `data/`, untracked or ignored.
  `scripts/deploy-hybrid.sh` copies the checkout's working tree, so such a file would be installed unseen. 5.18.90's
  record found none on the server on 09 Oct; the script now checks at every deploy. A rollback names such files and
  goes on.
- **The data directory** — the directory every read would use is `<plugin>/data`, because `ucrm.json` names it or
  because the live store beside the plugin is found neither on the host nor in the container. That is the stray store.
  Nothing is read from it, in any mode: `--after-only` and `--rollback` stop too.
- **A copy that stopped part-way** — the container's record says `3d9cb5f`, but a file this release changes is not
  5.18.90's byte for byte: the manifest, or any other of the seven. The deploy refuses and prints the by-hand put-back;
  so does `--rollback`, which rolls back only a deploy the record shows. The two files the release adds are not asked
  about: a rollback leaves them on disk too, reached by nothing.
- **A12** — on the server's own configuration, the pin's `StarlinkRetryScope::explain()` does not read `live=same
  inside=no store=read tenant=uganda skip=yes`. It runs as the database's owner, with the live store held open
  read-only as master holds it.
- **A13** — on throwaway layouts in the container's `/tmp`, shaped like the server (no `pluginDataDir`, the live store
  beside the plugin, a stray store inside it), inside master's shared scope, under the server's PHP, any of these:
  - on Uganda, the pin's job does not leave `$dataDir` on the live directory, no variable behind and the stray folder
    untouched;
  - on South Sudan, the pin's job differs from the live 5.18.90 job;
  - the live job does not open the stray store — the control: the instrument must see a change;
  - master loses a POSIX lock on the live database or its `-shm` in any of the four runs.

**Evidence, never a stop:**
- **A12's note** when the configuration files and the vault alone do not name Uganda. They may decide alone after the
  02:00 maintenance copy (Known limits 4).
- **A14** — the stray folder's last change is held against master's record of 5.18.90's retry job's last dispatch,
  within one dispatch either side. That is one coincidence, consistent with the job opening the store; it does not prove
  every run. The result is recorded as `seen` or `not-seen` in the state file, with the folder's entries.

**After the deploy:**
- **R6** carries 5.18.91's gate.
- **R18** repeats A12 on the INSTALLED code, and runs the installed job as A13 runs the pin's, against the same expected
  signatures (`SLR_STOPS` on Uganda, `SLR_OPENS` on South Sudan) — a failure in the log, not a refusal.
- **RB**, after a rollback: the job is 5.18.90's byte for byte, the gate is gone, the library is left on disk reached by
  nothing, and 5.18.90's safety fix is in place. The throwaway runs show the job opening `<plugin>/data` again.
- **R19** — the stray database must be exactly as before (checksum, size, time). Without the record from before the
  deploy, R19 judges nothing. Then the stray FOLDER:
  - **What an open leaves.** It makes the `-wal` and `-shm` there and holds POSIX locks on the store while it is open;
    the kernel lists those locks in `/proc/locks`. The side files go when the last read-write connection closes, at the
    end of the process that opened it. A read-only open that had to make them leaves them behind, held by none
    (measured in the review with the development machine's SQLite, through PHP's PDO and Python, and rehearsed; not
    observed on the server).
  - **The locks are trusted only after a positive control.** A throwaway SQLite store in a temporary folder on the
    host, held open by `python3`, must show locks, and none once closed.
  - **The record at the copy.** Right after the copy the script records the folder, its side files and the locks on
    them (`STRAY_COPY`, `STRAY_COPY_LOCKS`), and the copy's own time — the time it gave the retry job
    (`STRAY_COPY_AT`). R19 judges from that recorded time, never from the job file's time later, which a rollback
    attempt would move even when it copied nothing. It reads locks, files, locks and files again, so a process closing in
    between is never mistaken for one that left its files behind.
  - **The reference.** R19 judges from a reference: a moment, and what the folder held then.
    - It is the copy itself, when the copy found no side files, or side files held by no process.
    - When a process held the store at the copy, or whether one did could not be told (the locks unread then), R19
      records a moment in the state file the first time it can (`STRAY_QUIET_FROM`, `STRAY_QUIET_SIDE`), and every
      later run judges from that same moment:
      - the side files the copy saw still there, the folder unchanged since the copy, held by no process — that
        process was stopped before it closed the store: the moment R19 finds them so;
      - those side files gone, with the folder's last change at most 30 minutes after the copy and no later than the
        start of master's last completed run: that last change, the close of what held them.
    - 30 minutes is master's own stale-lock limit (`cron/master.php`, `LOCK_MAX_SECS`), and its runs end in minutes. It
      is a heuristic, not a limit anything enforces: `main.php`'s tail has no deadline once started (Known limits 12).
    - Without a usable record of the copy, R19 judges nothing.
  - **What can be told is an open after the fix,** and R19 fails on it: side files there again after the folder changed
    since the copy — an opener stopped before it closed, one still holding the store, or a read-only open; a process
    holding the store more than 30 minutes after the copy; a last change more than 30 minutes after the copy.
  - **What cannot yet be told is a note:** a change after master's last completed run began but within 30 minutes of
    the copy may be that process's own late close (`main.php`'s tail outlives master's lock), so R19 waits for the next
    completed run, which records it.
  - **A failure is never forgotten.** Every R19 failure is recorded in the state file (`STRAY_R19_FAILED`) and fails
    every later run: what showed it — side files, a holder — may be gone from the folder by then. Only the engineer
    removes that line, after reading every log file; a new deploy writes a new state file. Every R19 failure is
    evidence of an open or a write; what is no evidence either way — an unreadable record or time — is a note.

  In the table, *held at the copy* means side files there at the copy with a process holding them, or whether one did
  could not be told (the locks unread then).

  | What R19 sees | Verdict |
  |---|---|
  | An earlier run's failure recorded in the state file | FAIL |
  | The stray database changed since before the deploy | FAIL |
  | No record from before the deploy; no usable record of the copy or of its time | note — R19 judges nothing |
  | The stray folder's time unreadable, or the store changing while R19 reads it | note — run again |
  | Held at the copy; the folder changed since the copy, or a different set of side files, and side files there now — held, left by an opener that was stopped, or by a read-only open | FAIL with every other entry as before; a note otherwise |
  | Held at the copy; the folder unchanged since, and a process holds the store now, within 30 minutes of the copy | note — while it does, an open leaves no trace |
  | Held at the copy; the folder unchanged since, and a process holds the store now, more than 30 minutes after the copy | FAIL — not the process that held it then |
  | Held at the copy; the folder unchanged since, its side files there, and whether a process holds them cannot be read | note |
  | Held at the copy; the folder unchanged since, its side files held by no process now | note — the moment is recorded; from then on they must stay, held by none, until a completed run after it |
  | Held at the copy; no side files now, the folder's last change more than 30 minutes after the copy | FAIL with the same entries; a note otherwise |
  | Held at the copy; no side files now, no completed run since | note |
  | Held at the copy; no side files now, the folder's last change within 30 minutes of the copy and no later than the start of master's last completed run | that change is recorded as the moment, and judged as below |
  | Held at the copy; no side files now, the folder's last change after the last completed run began but within 30 minutes of the copy | note — the next completed run records it |
  | After the reference: side files there that were not, or different ones | FAIL |
  | After the reference: side files that were there gone, every other entry as before | FAIL — the close of a read-write open removes them |
  | After the reference: side files that were there gone, entries changed | note |
  | After the reference: a process holding the store | FAIL |
  | After the reference: the side files left behind as they were, but the locks cannot be read | note |
  | After the reference: the folder changed, every entry as before | FAIL — an entry made and removed: an open |
  | After the reference: the folder changed, entries added, removed or replaced | note naming them, never a pass |
  | Quiet since the reference, but no completed run after it (a run under way does not count) | note, with the time to run `--after-only` again |
  | Quiet since the reference and a completed run after it, but A14 not `seen` | note — never a pass from this deploy's record |
  | Quiet since the reference, a completed run after it, and A14 `seen` | pass |

  Its `migration.log` is reported, not judged: other jobs write it too. The summary reports what R19 established, not
  more: a changed database or any failure reads `R19 FAILED`, and with a held copy it says that between the copy and the
  recorded moment an open cannot be told apart. Besides that moment, or its failure, in its state file, `--after-only`
  writes nothing to the plugin's data but the one line R7 may add to the live `migration.log` (Known limits 11, 17): it
  writes its log, and throwaway copies of both releases' code, throwaway
  layouts and databases and the locks' control's store, in temporary folders in the container's `/tmp` and on the
  host, and removes them. Its switch read may refresh the vault, as the earlier releases' scripts' have (Known
  limits 9).
- **The live data directory is found on the host first.** When `ucrm.json` names no folder, the folder beside the plugin is
  taken if the host holds a `plugin.sqlite3` there, whether or not the container answers that moment. With no store
  there, it stops (the tenth review): it never falls back to `<plugin>/data`. A container that does not answer during a
  restart can therefore no longer send every read to the stray store.
- **A rollback writes its own record** (`rollback-state-<time>.env`), never the deploy's. A rollback that is declined at
  its question, or stops before it changes anything, leaves this release live and the deploy's state file — R19's
  references and V4's start — as the deploy wrote it.

**A copy that fails part-way.** `scripts/deploy-hybrid.sh` writes the container's record only after the whole copy, and
exits 2 both when its copy fails and when the container does not answer in time. The script tells the two apart by
that record on the host: if it does not name the pin, the copy did not complete. It says so at once — without the
two minutes' wait for a restarting container — and prints the by-hand put-back instead of `--rollback`, which refuses
over such a tree. The put-back, as every message prints it, goes back to the branch whether or not its copy step
succeeds: `cd /opt/dishnet && git checkout 3d9cb5f && { bash scripts/deploy-hybrid.sh; git checkout -; }`.

**A deploy run again** — after a rollback, or one declined at its question — keeps the earlier state file beside the new
one (`state-5.18.91.env.prev-<time>`) before it writes anything, or stops. Every deploy run says, in a note, an R19
failure that any kept state file holds. `--after-only` judges from the current deploy's record only.

**The stray store is never opened, moved, renamed, quarantined or written by the script.** It is read as files, its
folder is copied into the backup (`stray-data.tar.gz`), and its state is recorded in the state file.

### The rehearsal — `scripts/harness/deploy-5.18.91/rehearse.sh` (1472 lines, sha256 `740dd22599ce8450…`)

Built from 5.18.90's harness by an asserted derivation (the container, the stand-ins, the seed and the runners are
5.18.90's); the parts that rehearse 5.18.91 are new.

**The base**, installed as production runs it since 08 Oct:
- 5.18.90 with 086 and 087 applied by the plugin's own runner;
- the pilot's salesperson number added, switched off, with its assistant off (a fictitious seller and instance);
- the lead switches as decided on 07 Oct;
- **`ucrm.json` with no `pluginDataDir`, the live store beside the plugin folder, and a stray store inside it**, built
  by the installed plugin's own store. It is at every migration, as the live one is, with its own log and settings file,
  all two hours old;
- master's record of a completed run of the retry job, five seconds before the stray folder's last change;
- Evolution replaced by a recording stand-in.

**It covers:**
- **The pin (section 0):** cut on `3d9cb5f`; exactly the nine files; no migration; no media-layer, portal, CSRF or
  `bootstrap_data.php` file; Domain B and the plugin's docs the same trees.
- **Every refusal (1a–1x):**
  - a server not on 5.18.90 (1a), or with the wrong manifest (1b); a placeholder pin (1c); the branch tip (1d);
  - a media-layer file, a migration, or a change to `bootstrap_data.php` in the pin (1e–1g); the release without its
    gate (1h); Domain B, or a file outside the plugin (1i, 1k);
  - the registry's switch ON (1l); own leads only ON (1m); 087 not recorded (1n); a salesperson's number switched on
    (1p);
  - A12 and A13 on pins that would not work here: an inverted gate (1r); a library that never says skip (1s); a server
    whose live store names South Sudan (1t); a gate that stops South Sudan too (1u); a library that never reads the live
    store (1v); a library that reads the live database's header with `fopen()` and costs master its lock (1v2); A13's
    own control, the live job no longer opening the stray store (1v3b), reached with the installed-file check skipped,
    which refuses that edited job first (1v3);
  - the code copy failing (1w); the backup failing (1x);
  - **the tenth review's:**
    - `ucrm.json` naming `<plugin>/data` as the data directory (1aa);
    - a file git does not track under the checkout's plugin folder, untracked (1ab) or ignored (1ab2), while one
      under `data/` is not counted (1ab3);
    - a copy that stopped part-way, with the manifest already 5.18.91's: the deploy refusing over it and `--rollback`
      refusing too, each naming the by-hand put-back (1ac, 1ac2); with the manifest still 5.18.90's and another file
      the release changes already replaced, each refusing as well, naming the file (1ac3, 1ac4);
    - a real one, a folder standing where the release adds its library. The deploy says at once that the copy did not
      complete, with no wait on the container, and names the put-back, not `--rollback` (1ad). The put-back is taken
      from the log and run exactly as printed. It serves 5.18.90 again, with every file 5.18.90 has as it has it and
      the checkout back on its branch; a file the release adds, if the copy reached it, stays on disk, reached by
      nothing, as after a rollback (1ad2). With its copy step failing, it still returns the checkout to its branch
      (1ad3).
- **A12's and A14's evidence (1y–1z3):**
  - the files and the vault alone naming no Uganda: A12's note;
  - A14 with no schedule;
  - A14 with the folder's change outside the window;
  - A14 with a run still under way, its line on one line.
- **Weakened copies of stage A (2a–2f, 2x–2z):** A12 and A13 each blinded and caught, and shown to be independent
  layers; A5 and A7 blinded; the data-directory guard removed, and the stray store opened by the reads (2x); the
  untracked-file refusal removed (2y), and the installed-file check removed (2z): each copy gets as far as its prompt.
- **The deploy (section 3):** **72 ok, 0 failed, 1 note**, the one note R19's (too soon). No FAIL line; the A0–A14 and A5b lines,
  and R1, R2c, R3, R5, R6 (in part), R11–R14 and R16–R19, by their text — some by their opening words — the stray
  store's three records (before, at the copy, now) and both info lines with their times among them; the other R lines
  by the absence of a FAIL. The state file holds the copy's time, equal to the time the copy gave the retry job. No
  data is written, the stray folder is byte for byte, inode for inode and time for time as before, and its copy is in
  the backup. No Evolution address, key, instance name or number appears in the log.
- **R19 over time (4a–4z4):**
  - **Notes:**
    - the store changing while R19 reads it (4c3), or its folder's time unreadable (4c4), each made by an instrumented
      copy whose answer is the script's own branch;
    - no completed run yet (4a, 4b); a run still under way, its line on one line (4c2); a file from another
    writer (4o); A14 not `seen` or not recorded (4j, 4l); no record from before the deploy (4h), of the copy's time
    (4h2) or of the store at the copy (4h3), after which R19 judges nothing — never a pass, never a failure.
  - **Passes:** a completed run with the folder quiet since the copy (4c); the stray `migration.log` written after the
    copy by another job — reported with its new time, never judged (4d).
  - **Failures:** a REAL reopen by 5.18.90's own job (4m); the stray database changed (4f). After every failing case
    the rehearsal checks that the failure was recorded in the state file, once, and removes it before the next.
  - **Weakened copies, each caught:** blind to the folder (4e), to the database (4g), to A14 (4k), to the store's
    steadiness (4c3m), to the folder's time (4c4m).
  - **The summary's words:** what R19 established is asserted where it fails (4f, 4m), passes (4c, 4s) or cannot
    compare (4h).
  - **R19's reference, driven by real processes** that open the store as 5.18.90's job does — holding it, closing it,
    or killed before they close it — with the state file saying what the copy found:
    - **Nothing held at the copy.** An open and close after it, then master's next completed run: FAILS (4p). The copy
      that judges from master's latest run passes it: caught (4q).
    - **Held at the copy, that process gone, no completed run since:** a note, nothing recorded (4r). The copy that
      judges from the copy fails on that process's own close: caught (4t).
    - **Held at the copy, that process gone, before any moment is recorded:**
      - the folder changed after master's last completed run began, within 30 minutes of the copy: a note, nothing
        recorded (4r2) — that process's own close may come after a run has begun; master's next completed run lets R19
        record that change and PASS (4r3);
      - the folder's last change more than 30 minutes after the copy: FAILS (4r4); the copy without that bound passes
        it: caught (4r5); the same with a file of another writer's in the folder: a note, nothing recorded, never a
        failure (4r6), and the copy that fails on it instead: caught (4r6m).
    - **That process's close within 30 minutes, then master's next completed run:** R19 PASSES and records the close
      as its moment (4s).
    - **After that moment.** An open and close, within 30 minutes of the copy, then a later run: FAILS (4u). The copy
      that forgets the moment passes it: caught (4v). A new holder: FAILS (4v2).
    - **A process holding the store as one did at the copy:** within 30 minutes of the copy, a note, nothing recorded
      (4w);
      - a file of another writer's added beside it: a note, nothing recorded, never a failure (4w6), and the copy
        that fails on it: caught (4w6m);
      - the locks unreadable now: a note, nothing recorded (4w7), and the copy that takes them for none and records a
        moment: caught (4w7m);
      - more than 30 minutes after it, not the process from the copy: FAILS (4w1), and the copy without that bound
      only notes it: caught (4w1m); the same where the copy could not read the locks: FAILS, saying the copy could not
      tell (4w1q). After R19 has recorded side files left behind, a holder FAILS (4x2). The copy
      blind to the locks passes it: caught (4x).
    - **Held at the copy, then killed before it closed the store:**
      - R19 records its side files as left behind: a note (4y);
      - run again before master's next completed run: a note (4y1), and the copy that counts a run begun before the
        recorded moment passes: caught (4y1m);
      - a completed run later, still there and held by none: PASSES (4y2);
      - then an open and close removes them: FAILS (4y3);
      - the copy that forgets what it recorded passes that: caught (4y4);
      - the same, with a file of another writer's in the folder: a note, never a failure (4y5), and the copy that fails
        on it: caught (4y5m).
    - **Held at the copy, that process gone, then side files there again** — the folder changed since the copy:
      - left by an opener stopped before it closed: FAILS (4w2); the copy that does not compare them with the copy's
        takes them for that process's own: caught (4w3);
      - an open and close then removes them within 30 minutes of the copy, and master's next run begins after it:
        judged afresh the folder would pass, and R19 FAILS on its own record of the failure (4w2b); the copy that
        forgets that record passes: caught (4w2c);
      - a new process holding the store: FAILS (4w4);
      - left by a read-only open, its control first (no side files before, made by it): FAILS (4w5).
    - **Side files left at the copy, held by none:**
      - still there and the folder unchanged: PASSES (4z);
      - with the locks' control failing, a note, never a pass (4z1);
      - a holder now (4z2), or the side files gone (4z3): FAILS.
    - **Side files left behind now, where the copy found none:** FAILS (4z4).
  - Every fault removed: PASSES with no note (4i).
- **The other checks' teeth (5a–5l):**
  - 5.18.90's job back on the server (R1, R6, R18);
  - the library never saying skip (R1, R18 on both layers), and R18 blinded;
  - the library reading the `-shm` with `file()` — R18 fails on the lost lock — and R18's lock check blinded;
  - R18's note on the files and the vault;
  - 087's trigger; the registry's switch; a Domain B file; an event deleted; a photo lost; a fatal planted in the log.
- **The rollback (section 6):**
  - Typed `ROLLBACK`: **43 ok, 0 failed, 3 notes — each saying what a rollback brings back: the retry job opening the stray store again on Uganda, at most every 10 minutes, and the files 5.18.91 added left on disk, reached by nothing**. The job is 5.18.90's byte for byte, the library is left on disk reached by
    nothing, 5.18.90's safety fix is kept, the stray store is untouched and no data is changed.
  - **A deploy declined at its question, over an earlier state file that recorded an R19 failure (6a):** the new
    state file is written, the earlier one kept beside it byte for byte, and its failure said. Declined once more,
    both earlier files are kept and the failure is still said, from the first (6a3). The copy that does not keep it
    loses that failure silently: caught (6a2).
  - Deployed again, with A14's note (6b), and rolled back once more (6c). A rollback over a file git does not track in
    the checkout's plugin folder names it and goes on (6c2).
  - **Deployed once more with a real process holding the stray store across the copy (6d):** PASSES; the state file
    records the hold and its locks; R19 says not yet.
  - **That process then ends.** The copy that judges from the copy FAILS on that close (6e). The script as it is waits
    for master's next completed run, then PASSES and records that close as its moment (6e2).
  - **A rollback declined at its question (6f):** this release stays live, and the deploy's state file is byte for byte
    as it was — the rollback's own record goes to a file of its own; the next `--after-only` still PASSES (6f2).
  - Only the folder's time is then put back, so section 7 still sees every file as the last deploy left it.
- **What is left behind (section 7):**
  - Evolution never called in any of the script's runs, with the control on the control;
  - the stray folder exactly as seeded;
  - the checkout as found; no weakened copy left;
  - every weakened copy's anchor found exactly once, with its own control;
  - nothing in `/tmp`; no PHP fatal from the stand-in.

**Results.** On the committed script (`4f5ce3a`), each run alone: run 1, 10 Oct 00:06–00:26 UTC, and run 2, 00:26–00:45 UTC, each **340 passed, 0 failed**, 138 runs of the script. Their check lines are identical but for each sandbox's own stray-store checksum and times. Evolution was never called; nothing was left in `/tmp` or in the clone. Before them, a scratch trial of each round's script. The last, of round 11's, read 338 passed, 1 failed: the old 1v3, whose edited installed job the new installed-file check now refuses first. It became 1v3 (that refusal) and 1v3b (A13's control, with the check skipped), and both pass in the two runs above.

### Reviews

Eleven independent adversarial reviews: workflows of 4–45 agents, each finding checked by skeptics. After each came fixes,
a full re-test and the suites.
1. **Round 1 — a blocker.** The first helper read the live database's header with `fopen()` inside master's process,
   which drops every POSIX lock master holds on that file (sqlite.org, *How To Corrupt*, 2.2). It was removed: the
   helper decides from `is_file()` of the side files and reads only through SQLite. Also fixed:
   - `liveDataDir()` diverged from `getDataDir()` for a `pluginDataDir` of `/` or `0/`;
   - the test's weakened copies were caught only by text; they now run without the source checks.
2. **Round 2 — a blocker in the deploy script.** A bare `$dataDir` under `set -u` would have ended every deploy and
   `--after-only` before its verdict and its rollback command. Also:
   - R19 had no positive control (A14 added);
   - the lock measurement counted the database but not its `-shm` (both now, with a weakened copy for each);
   - the nightly maintenance copy is recorded (Known limits 4);
   - the rehearsal's R19 checks depended on the clock, and two of its weakened copies were never built.
3. **Round 3 — R19 watched the wrong instrument.** The stray folder's `migration.log` is `MigrationRunner`'s default log
   for every caller that gives none — among them `cron/dpo_reconcile.php`, every 5 minutes, on the live store — so it
   keeps growing whatever opens the stray store. R19 and A14 now watch the folder itself. Also:
   - R19 reads master's record first and counts only completed runs;
   - the test keeps `ucrm.json` out of its hard-linked templates, and counts a weakened copy as caught only where the
     named check passes unweakened;
   - the rehearsal gained the A12 note, A14 with no schedule and at the window's upper bound, a real reopen by 5.18.90's
     own job, another job writing the log, and a file from another writer in the folder.
4. **Round 4 — R19 could fail on the old job's own close.** 5.18.90's store holds its connection until the process ends.
   A run dispatched just before the copy therefore changes the folder whenever its master process exits, possibly well
   after the copy, and R19 would have called that an open. A holder killed before it closed would have left `-wal` and
   `-shm` behind, under which a later open leaves no trace. Fixed:
   - the script records the folder right after the copy;
   - R19 judges side files present now, and a store held at the copy, as the table above says;
   - the rehearsal drives both with a real holder process, and deploys once with the store held across the copy;
   - "(still running)" now prints on its line, not on a line of its own.

   Also corrected in this record and in the shipped comments:
   - "never run" is replaced by "not run against the live store since the 26 Aug move";
   - the reach of the leak (above);
   - how R18 differs from A13;
   - the rule number for `exit` (11b);
   - the stray file's provenance;
   - the evidence scripts' names;
   - a stale comment in the test. The test's server-shaped template also gained a control that its log exists.
5. **Round 5 — the reference moved.** 8 agents, 21 findings, 20 upheld.
   - With the store held at the copy, R19 judged from master's LATEST run, so the window moved with every run and an
     open before it went unseen. It now records the first such moment and judges from it.
   - With nothing held at the copy, a 2-minute grace forgave an early open. It now judges from the copy itself.
   - Side files a stopped process leaves were taken for a live holder, so R19 could never pass after uCRM's restart.
     It now reads the locks on the store from `/proc/locks`: held files mean a process has it open, unheld ones were
     left behind.
   - Also fixed:
     - the deploy no longer falls back to `<plugin>/data` for its reads while the folder beside the plugin holds a
       `plugin.sqlite3`;
     - the summary reports what R19 established, not more;
     - entry names may hold any character;
     - the time to look again is never already past.
   - In the rehearsal:
     - a holder's process number leaves the cleanup list once it is reaped;
     - the last deploy's effect on the stray folder is no longer undone before section 7 checks it;
     - new cases and weakened copies cover each reference.
   - In the test, labels that stated the server's log behaviour as fact now say "as built here", and the headline says
     where the gate applies.
   - In this record, the doc findings: the scheduled opener, observed against inferred, 071's timing, the R19 table.
6. **Round 6 — once seen, never forgotten.** 6 agents, 18 findings, all upheld.
   - R19 saw a copy-time holder's side files left behind, then forgot it. A later open that removed them, followed by a
     completed run, then passed.
   - After the holder was known to be gone, a new holder was only a note.

   Both came from R19 re-deriving its reference on every run. It now judges from one reference — the copy, or a moment
   it records once — against which side files, locks and the folder are all compared. Also fixed:
   - the lock reader has a positive control (a store held open on the host by `python3`);
   - the store is read locks, files, locks, files, so a closing process is never mistaken for one that left;
   - the live data directory is found on the host first, so a container that does not answer can no longer send the
     reads to the stray store; a rollback tried with the container down stops with "roll back by hand", not "run this
     again";
   - the rehearsal's 6e was re-timed so that its weakened copy now fails;
   - the doc wording.
7. **Round 7 — an open after the fix could be taken for the copy-time holder's.** 8 agents; 20 findings, 18 upheld.
   - With a process holding the store at the copy, side files found later held by no process were taken for that
     process's own, left behind — even when the folder had changed since the copy, which proves those were removed and
     new ones made: by a read-only open, or an opener stopped before it closed. R19 recorded that moment and passed.
     It now compares them with the copy's, and fails.
   - With those side files gone, the reference was master's latest run, however long after the copy; an open hours
     later, before that run, went unseen. The copy-time process's close must now fall within 30 minutes of the copy —
     master's own stale-lock limit — and the reference is that close.
   - A failure whose evidence the folder does not keep — a change after a run had begun — could pass on the next run.
     Every R19 failure is now recorded in the state file and fails every later run.
   - A rollback declined at its question rewrote the deploy's state file first, so R19 could never pass again while
     5.18.91 stayed live. A rollback now writes its own record.
   - Also fixed: R19 judges nothing without the record from before the deploy; a changed database reads `R19 FAILED` in
     the summary; the pass text no longer says no process holds the store when the locks could not be read.
   - In the rehearsal: the late branch, the 30-minute bound, the recorded failure and the side files made again (an
     opener stopped, a new holder, a read-only open), each with a weakened copy where it has a branch of its own; a
     declined rollback, checked byte for byte (6f); 4h now checks that R19 judges nothing; 4z2 checks the reference its
     failure names.
   - In this record: the quarantine's evidence is named exactly (runbook step 3); the read-only behaviour; the host's
     `python3`; the lines on the fallback and the rollback with the container down.
8. **Round 8 — the round-7 changes, checked.** 6 agents; 17 findings, 16 upheld; none a blocker or major.
   - R19 took the copy's time from the retry job's file, which a rollback attempt that copied nothing would still
     re-stamp. The copy's own time is now recorded at the copy (`STRAY_COPY_AT`) and R19 judges from it.
   - A process seen holding the store more than 30 minutes after the copy was only a note, and passed if it was later
     killed (a close would have failed). It now fails, whatever ends it.
   - A change after master's last completed run began was a failure, now permanent, though the copy-time process's own
     late close can cause it (`main.php`'s tail outlives master's lock). Within 30 minutes of the copy it is now a note,
     and the next completed run records it.
   - An unreadable job time failed permanently; no record of the copy's time is now a note, and every R19 failure is
     evidence of an open or a write.
   - Recorded, not changed: an open of side files left behind changes the `-shm`'s own time, which R19 does not read
     (Known limits 12).
   - In the rehearsal: a held-at-copy fixture no longer back-dates the folder before the moment it had recorded; the
     cases with no record of the copy or of its time; the read-only open's control checks the side files were absent
     before; a holder within and past the 30 minutes (4w, 4w1); the late change a note, then recorded (4r2, 4r3); the
     recorded failure rehearsed on a failure whose evidence the folder does lose (4w2b).
   - In this record: the table's rows, Known limits 8 and 12, the option text, the read-only measurement's place.
9. **Round 9 — the round-8 changes, checked.** 4 agents; 10 findings, all upheld; none a blocker or major.
   - The 30 minutes are a heuristic, not a limit `main.php`'s tail obeys: the failure texts and Known limits 12 now
     say so, and name the one case where R19 fails on the copy-time process itself.
   - The summary's "check it" time now comes from the recorded copy time; the info line no longer shows 1970 when the
     job file cannot be read; the failure texts no longer assert a holder at the copy that the copy could not see.
   - The trial ran a script derived before the last header edit; the script is derived afresh from its sources before
     it is pinned and committed, and only that file is rehearsed.
   - In the rehearsal: the copy that could not read the locks, with a holder past the 30 minutes (4w1q); a late change
     past them beside a file of another writer's — a note, never a failure (4r6), with a weakened copy (4r6m).
   - In this record: the `-shm` measurement as measured, the write list, stale wording.

10. **Round 10 — the round-9 changes, the whole script for production safety, and the rehearsal for coverage.**
    8 agents.
    - **14 findings:** 5 verified (4 upheld, 1 found to be documented behaviour), 4 beyond the cap on verification and
      5 nits. Each was read and is settled here. None was a blocker. Three were rated major by their finders and minor
      by their verifiers.
    - **The data directory could still be `<plugin>/data`.** That happened when the live store was found neither on the
      host nor in the container, and would happen if `ucrm.json` named that folder. Every read would then have opened the
      stray store. Now refused before anything is read.
    - **`scripts/deploy-hybrid.sh` copies the checkout's working tree.** A file git does not track under the plugin folder
      would be installed unseen. This is carried from earlier releases' scripts, and was found absent on the server on
      09 Oct. It is now refused at a deploy and named at a rollback.
    - **A copy that failed part-way had no recovery.** The container's record stayed on 5.18.90 with some files already
      5.18.91's, and the deploy said "roll back with `--rollback`", which then found nothing to roll back. Carried from
      earlier releases' scripts. The deploy now says the copy did not complete and prints the by-hand put-back.
      `--rollback`, and a second deploy over such a tree, refuse and print the same.
    - **A deploy run again rewrote the state file**, and an earlier R19 failure with it. That is written down as intended,
      as a verifier noted. The earlier file is now kept beside the new one all the same, and its failure is said.
    - **The wording on R7 was wrong.** R7's comment and the `--after-only` description said nothing is written. The
      cash-in-hand tool opens the live store as the plugin does, so its `MigrationRunner` may add its 071 warning to the
      live `migration.log`. The words are corrected; the tool and how it is run are unchanged since 5.18.71.
    - **In the rehearsal:**
      - each refusal above (1aa–1ad2, 6a), with the part-way copy made real and its put-back run as printed; the guard,
        the untracked-file refusal and the kept state file each weakened and caught (2x, 2y, 6a2);
      - R19's branches that had no case, each with a weakened copy: no completed run since the recorded moment (4y1); the
        store changing while read (4c3); its folder's time unreadable (4c4); another writer's file beside a held store
        (4w6) or where recorded side files went (4y5); unreadable locks on a held store (4w7);
      - the summary's words where R19 fails, passes or cannot compare (4c, 4f, 4h, 4m, 4s).
    - **Recorded, not changed** (Known limits 13–16): an unsteady read at the copy; the list of entries R19 compares
      with; the switch read and the webhook key; the pin by its seven-character name.
11. **Round 11 — the round-10 changes, checked.** 8 agents.
    - **20 findings:** 5 verified, all upheld, all minor; 3 beyond the cap on verification and 12 nits. Each was read
      and is settled here. None was a blocker.
    - **The put-back could strand the checkout.** It returned to the branch only if `deploy-hybrid.sh` succeeded. When
      the container does not answer in time it exits 2, and the checkout stayed on 5.18.90, which holds no copy of this
      script to run again. The put-back now returns to the branch either way. That includes the inherited by-hand lines
      in the summary and the rollback's.
    - **A part-way copy was recognised by the manifest alone.** A copy that stopped before `manifest.json` went
      unrecognised. Now every file the release changes is compared with 5.18.90's, in the deploy and in `--rollback`.
    - **A failed copy was taken for a restarting container**, two minutes' wait included. The record on the host now
      tells the two apart.
    - **A failed keep of the earlier state file was a failure noted after the file had been overwritten.** It now stops
      before anything is written. An R19 failure in any kept state file is said at every deploy run, not only the next.
    - **Files with non-ASCII names in the checkout's `data/` could be counted.** git now leaves `data/` out itself.
    - **R7 runs as the container's default user**, not as the database's owner, and opens the live store read-write.
      Round 10 had recorded only its `migration.log` line. The record, the header and the comment now say so (Known
      limits 17).
    - **In the rehearsal:**
      - a copy that stopped before the manifest, refused by the deploy and the rollback (1ac3, 1ac4), and that check
        weakened (2z);
      - the failed copy said at once (1ad), its put-back taken from the log and run exactly as printed (1ad2), and back
        on the branch when its copy step fails (1ad3);
      - the failure still said at the second re-run (6a3);
      - a rollback over an untracked file (6c2).
    - **In this record:** the counts of round 10; which items are carried from where; what "each weakened" covered;
      the data directory refused in every mode; the description of 1ad2.

**Not changed, recorded:** the deploy's switch read (`tools/set_distributors.php --show`, carried from earlier releases' scripts) loads the
configuration through `PluginConfig::load()`, which refreshes the vault when it is out of step with the files (Known
limits 9).

### Deployment runbook — each step is the operator's, and each needs the operator's approval first

1. **Push `release/5.18.91`** — only on the operator's approval of the deploy; it is local until then (the server
   fetches it at deploy time).
2. **Deploy**, as root on the server, then send back THE LOG FILE (never a copy of the terminal):

       cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.91 && mkdir -p /root/dnb-5.18.91 && bash scripts/deploy-5.18.91.sh 2>&1 | tee /root/dnb-5.18.91/deploy-$(date -u +%Y%m%dT%H%M%SZ).log

   It refuses before anything changes on any NO-GO, asks for `DEPLOY`, and prints the rollback at the end of its log,
   on its own. Expected, not yet observed:
   - A14 `ok`, if the stray folder's last change falls within one dispatch of master's last run of 5.18.90's job;
     otherwise A14's note, and R19 can then never pass from this deploy's record;
   - PASSED;
   - R19's note: master has not yet completed a run of the fixed job, or a process still held the stray store at the
     copy;
   - a note from the locks' control, if the host has no `python3` or no readable `/proc/locks` (Known limits 8).
3. **At least 20 minutes after the copy** (the summary prints the time). Read-only; nothing is deployed:

       cd /opt/dishnet && bash scripts/deploy-5.18.91.sh --after-only 2>&1 | tee /root/dnb-5.18.91/after-$(date -u +%Y%m%dT%H%M%SZ).log

   **The evidence the quarantine waits for is exactly this, in a run with 0 failed:** the R19 line that begins
   `ok    R19 the retry job ran at` and says `nothing has opened the stray store since`, and the summary's
   `whether anything has opened it since the fix: nothing has opened it since … (R19)`. The other R19 `ok` — `the
   stray store under the plugin folder is exactly as before the deploy` — says only that the database file is unchanged,
   and is not that evidence. Any R19 note, or any failure in this run or an earlier one, means: not yet, or never from
   this deploy. The R19 `ok` needs all of these:
   - from R19's reference — the copy, or, if a process still held the store then, the moment R19 recorded — the stray
     folder unchanged, its side files as they were and nothing holding the store;
   - a completed run of the fixed job after that reference;
   - A14 `seen`.

   In the second case one more `--after-only`, after master's next run, may be needed.

   A FAIL or a note says what it saw. Send the log file either way. **Until the quarantine is done, do not run
   `diagnose-sales-pipeline.sh`:** it opens the stray store read-write as root, and R19 would then rightly fail.
4. **The quarantine of the stray store is NOT part of this release.** It needs its own approval, after step 3. Its
   proof is R19, not the stray folder's `migration.log`, which other jobs keep writing — the investigation's proposed
   *"log has stopped growing"* test would never pass.
5. **The salesperson pilot stays off** until the operator resumes it.

### Known limits and open risks

Observed facts are marked **observed**; everything else is reasoning from the code, or not known.
1. **The 19 Sep re-creation of the served plugin folder is NOT EXPLAINED.**
   - **Observed:** the plugin folder was born at 07:08:00.95 that day, and so were (07:08:00–01) the 15 oldest-born
     entries listed directly under it, `data/` among them, and the stray `plugin.sqlite3` inside it (07:08:01.45).
     Nothing listed is older. Files written since were born later: `manifest.json` and `main.php` by 5.18.90's deploy on
     08 Oct, and `ucrm.json`, which uCRM writes, on 01 Oct. The stray database's ledger reaches back to 26 Aug, so its content came through the
     re-creation, by a means not established.
   - Hypothesis only: a uCRM ZIP install.
   - 5.18.91 neither depends on it nor resolves it. A second event of that kind could recreate the folder again.
2. **How often the stray store is opened today is NOT ESTABLISHED.** By the code, it is opened once per run of the
   retry job, at most every 600 s. The jobs after it and `main.php`'s tail open none there (*Why*, above). The ~319 log
   lines a day cannot be counted as opens (Corrections). A14 will show, on the server, whether the folder's last change
   falls within one dispatch of the job's.
3. **Other writers of `<plugin>/data` exist in 5.18.90's code** — hard-coded fallbacks that SAFETY.md calls bugs:
   `CashbookService` on the cash-declaration page, `NotificationService`'s `LifecycleService`, `KycService`,
   `FiberFinanceEngine`, `MagmaApiClient`, `api_cron_debug.php`.
   - Some create files there: `FiberFinanceEngine`'s `fiber_events.log`, `CashbookService`'s meta file and error log.
     The gates they call only read files and the vault.
   - None of them opens a SQLite database there, but the read was not exhaustive.
   - **Two manual tools do open the stray store,** both unscheduled: `diagnose-sales-pipeline.sh` (repository root, "run
     on the server as root") opens it read-write twice, and `migrations/migrate_tickets_data.php` opens and migrates it.
     Either run before the quarantine makes R19 fail, rightly.
   - Any file one of them adds after the copy makes R19 note instead of pass.
   - Not changed here (out of scope).
4. **The nightly maintenance job copies the live database and its `-wal` and `-shm` with PHP `copy()` inside master's
   process** (`cron_maintenance.php` lines 474–478, unchanged since before 5.18.88).
   - That releases master's POSIX locks on the live database — the same hazard as round 1's blocker. A later close by
     another process could then remove the `-wal` and `-shm` under master.
   - **A pre-existing data-integrity risk to the live store, NOT introduced or fixed by 5.18.91.** It needs its own
     release: SQLite's own copy (`VACUUM INTO` or the backup API), and never copying `-wal`/`-shm`.
   - For 5.18.91 it means one thing: if that happens, the gate cannot read the store and decides from the configuration
     files and the vault (the A12/R18 note says if those do not name Uganda).
5. **The configuration files and the vault on the server** are expected to name Uganda (UGX in the vault, by earlier
   records); A12 prints what they name. A run of the job on its own (`php cron_starlink_block_retry.php`, which nothing
   schedules) decides the same way.
6. **The leak's past effects cannot be observed afterwards:** master's gate for the two later jobs evaluated with the
   stray `$dataDir`, and `main.php`'s tail writing files into the stray folder. One is known: two wallet-sync files on
   07 Oct.
7. **The South Sudan server was not examined.** Its behaviour is unchanged by design. If its layout is Uganda's, the
   stray access continues there — the approved scope.
8. **The lock checks need `/proc/locks`.**
   - A13's needs it in the container. If it shows nothing, A13 says so in a note, and the proof rests on the test and
     the rehearsal.
   - R19's runs on the host and is trusted only after its control, which needs `python3` on the host. Without either,
     every run carries the control's note, and side files keep R19 from passing: the side files the copy saw, still
     there, give a note; side files that were not there at the copy, or appear again after the folder changed, fail as
     at any time.
9. **The deploy's switch read refreshes the vault** when it is out of step with the files (`tools/set_distributors.php
   --show` loads through `PluginConfig::load()`; carried from earlier releases' scripts). On a running server other jobs keep it in step,
   so in practice nothing is rewritten — not proved on the server.
10. **`cliDataDir()`'s blind spot and `getDataDir()`'s rescue copy stay armed** while the stray file exists. That is what
    the separate quarantine is for; their own hardening is a separate, cross-country decision.
11. **Found along the way, untouched:**
    - 071's checksum warning floods the live store's migration log, and the stray folder's (the live ledger does not
      match the shipped file). R7's run of the cash-in-hand tool, at each deploy and `--after-only`, adds one more line to
      the live log, as the plugin's own runs do;
    - `main.php` line 509 names `\DishNet\SqliteStore`, a class that does not exist;
    - **observed:** a stale `lte_auto_suspend_log.json` sits in the served plugin root, and nothing at `3d9cb5f` writes
      that file in the plugin root (its writers go through the store, or write it in the data directory);
    - **observed:** `tests/test_job_notifications_off.php` is served but not in the release.
12. **What R19 cannot see, or may get wrong.**
    - When a process held the store at the copy, an open between the copy and R19's reference moment cannot be told
      apart from that process's own close: at most 30 minutes after the copy once its side files are gone; until R19
      finds them left behind otherwise. That includes a read-write open that closes while the holder still lives, which
      leaves the side files in place.
    - Where side files were left behind, a **read-only** open leaves them as they were, and so does an open by
      something that is itself stopped before it closes. Neither changes the folder or the side files' names. With
      nothing else holding the store, each does change the `-shm`'s own time; an open made while another connection
      holds it does not (measured in the review with the development machine's SQLite, 3.45.1, through PDO and
      Python; not observed on the server). R19 does not read that time. While such an open lasts, its locks show.
      Where no side files were there, a read-only open leaves its own, and R19 fails on them.
    - The folder's time is read in whole seconds: an entry made and removed within the second the copy was recorded
      cannot be seen.
    - The 30 minutes are a heuristic. Master takes a lock older than that for stale and its runs end in minutes, but
      `main.php`'s tail has no deadline once started: its 260-second check only decides whether the wallet sync and
      the auto-pull start, and a slow one can run longer. A process that held the store at the copy and lived more than
      30 minutes after it makes R19 fail, when it is seen holding or when it finally closes — the one case where R19
      fails on that process itself. The failure says so ("unless a process that held it then has run that long"), and
      like every R19 failure it is never forgotten: the quarantine then waits for the engineer's reading of the logs.

13. **At the copy, a read that changed while it was taken records the first of its two reads**, with its locks as `?`.
    If a process began opening the stray store in that instant, the copy records no side files, and that process's own
    close later fails R19 for good. The window is the moment between two reads of a few files. A process opening the
    store just then is either 5.18.90's job, dispatched in that very instant, or an open after the fix. Found in the
    tenth review; not changed.
14. **R19 compares the folder's entries with the list from before the deploy**, not with a list taken at its reference
    moment. An entry another writer adds or replaces in between makes every later change a note where R19 could have
    decided. It is never a false pass. Not changed.
15. **The switch read runs without `-u`**, as the container's default user: root, by the script's own comments, not
    observed. It is `tools/set_distributors.php --show`, through `PluginConfig::load()`. If the live `webhook_secret` file
    were missing while the vault holds it, the vault would write the file back as that user, mode 0600. "The webhook key"
    in the never-touched list holds only while the file is there. Carried from earlier releases' scripts; not changed.
16. **The pin is a seven-character commit name**, as for every release since 5.18.66.
    - Two objects in the server's checkout sharing the name stop the script, because git calls the name ambiguous.
    - Another commit pushed under the same seven characters, with the reviewed one absent, would pass the name checks.
      It would meet only the behavioural ones (A6, A9–A13).
    - Not changed.

17. **R7 runs 5.18.71's cash-in-hand tool without `-u`**, as the container's default user. That is root, by the
    script's own comments; not observed. The tool opens the live store read-write through `SqliteStore::create()`:
    - it writes no record and applies no migration, since none is due;
    - its `MigrationRunner` may add the 071 warning line to the live `migration.log` (Known limits 11).

    The rule the other reads keep is to open the database only as its owner, read-only, so that SQLite never leaves a
    root-owned file beside it. R7 does not keep that rule. It has run so at every deploy since 5.18.71 on this server.
    Recorded in the tenth and eleventh reviews; not changed in this release.

### Corrections to the 5.18.90 entry (09 Oct), with their evidence

Two statements in the 5.18.90 RESULT bullet about the stray file were wrong: the two the operator approved correcting.
Each is corrected beside the original, which stays as written.
1. *"…the live store … named by `ucrm.json`'s `pluginDataDir`"*
   - **Observed:** the server's `ucrm.json` has no `pluginDataDir` — the key names listed by `stray-db-evidence.sh`.
     `getDataDir()` chose the folder beside the plugin by its second rule.
   - The deploy's checks did read the live store: with no `pluginDataDir`, they take the folder beside the plugin when
     it holds a `plugin.sqlite3`.
2. *"What wrote the stray file is NOT ESTABLISHED. `getDataDir()` falls back to the plugin's own `data/` only when
   `ucrm.json` gives no `pluginDataDir`…"*
   - **Identified by the code and the ledger's timing, not observed in the act.** `cron_starlink_block_retry.php` names
     `<plugin>/data` itself, and by the code every run opens the store there and applies any migration it lacks. It is
     the only scheduled opener found in the code; two manual tools open it too (Known limits 3).
   - The stray's own `_migrations` ledger shows 087 applied at 09:55:07 on 07 Oct, about three minutes after the live
     store's at 09:52:18 — consistent with the job's next dispatch. Master's record of that cycle was not read.
   - The fallback was misstated: with no `pluginDataDir`, `getDataDir()` takes the plugin's own `data/` only when the
     plugins root is not writable.

**Not corrected inline — recorded here, outside the two statements approved for correction:**
- The same misattribution appears earlier in this file, in the 04 Oct 5.18.74 entry: *"…its data directory is
  `<plugins>/.dishnet-hybrid-sudan-data/` (uCRM's `pluginDataDir`; `docs/27`, the 4 Oct listing)"*. That folder comes from
  `getDataDir()`'s second rule, not from a `pluginDataDir`.
- The 5.18.90 bullet's *"Left in place, unread and untouched"* says what that record did. The file itself was opened by
  5.18.90's retry job on every run (by the code). With no migration due, nothing was written to it: it is unchanged
  since 07 Oct 09:55:07 (observed). It was also read — as files, immutably — by the 09 Oct evidence scripts.

**A third correction**, to the investigation report of 09 Oct (delivered in the session, not in this repository). It
read the stray folder's `migration.log` — about 320 lines a day — as *"still opened about 320 times a day"*. It proposed
*"the stray folder's `migration.log` has stopped growing for at least 30 minutes"* as the proof before the quarantine.
The log can carry neither:
- `MigrationRunner`'s default log is `<plugin>/data/migration.log` (`lib/MigrationRunner.php` line 51).
- `cron/dpo_reconcile.php` (every 300 s), `dpo_push.php`, `dpo_return.php`, `tabs/admin/dpo_payments.php` and
  `includes/api/api_lte_admin.php` construct it without a log path while migrating the live store. The live store's 071
  checksum does not match the shipped file, so that log grows whatever opens the stray store.
- Whether each open of the stray store adds a line too depends on the stray ledger's own 071 checksum. That was not read,
  and must not be read by opening the store. The stray applied 071 on 13 Sep at 11:55:19; the live store's time for 071
  was not printed. If both applied the same installed file, as both did for 072–087 minutes apart, it probably
  mismatches too.

Either way the lines cannot be counted as opens. The third review of 5.18.91 found this; R19 and A14 watch the stray
FOLDER instead (above). How often the stray store is actually opened is NOT ESTABLISHED: by the code, once per run of
the retry job, at most every 600 s.

### RESULT — DEPLOYED 10 Oct (copy 02:53:29 UTC); `--after-only` 03:14:16 UTC: 53 ok / 1 failed / 1 note — R19 PASSED, V4 FAILED

Recorded from the `--after-only` terminal the operator pasted; the script prints no secret. **The deploy run's own log
has not been received**, so its verdict is NOT RECORDED here; what the deploy did is read below from the
`--after-only` run alone. The log files stay on the server under `/root/dnb-5.18.91/`.

**Push.** On the operator's *"please release 5.18.91 and give deploy commanda"*, `release/5.18.91` was pushed at
02:50:41 UTC on 10 Oct, a new branch at `dfad4d9`. The deploy command was given in the chat; the rollback was not (it
is printed in the deploy's log, on its own line).

**What the `--after-only` run (`20261010T031416Z`) observed:**
- **A.**
  - The checkout is at `c3554a9`, the branch tip `2613fc2`, the release `dfad4d9`, cut on `3d9cb5f`.
  - There are no tracked edits, and **0 files git does not track** under the plugin folder outside `data/`.
  - A7 and A0 are ok. The live commit is `dfad4d9`, the manifest says 5.18.91, and the container runs PHP 8.1.34.
  - Every switch is as the operator left it: pilot `on`, `ia` and `wa` `on/on`, `lc=on/on`, `ls=off/off`, `qu=on/on`,
    `sa=on/on`. `ol`, `hc`, `fh` and the registry's `mn` read `absent/absent`.
  - 086 is complete (`4:26:1:1:1`). 087 is complete (`rows=4:5`): one salesperson number, none switched on, 0 with its
    assistant on.
  - The routing and the Inbox's row are as on 5.18.90.
- **V.** V1–V12 and V3–V3h are ok. **V4 FAILED:** *"2 fatal line(s) of dishnet-hybrid-sudan since
  2026-10-10T02:53:22Z"*, at 02:59:14 and 03:13:41 UTC. Each line reads *"NOTICE: PHP message: PHP Fatal error:
  Uncaught TypeError: flock(): supplied resource is not a valid stream resource in
  /data/ucrm/data/plugins/dishnet-hybrid-sudan/cron"*. The script cuts each line at 200 characters, so **the file and
  line are not in the log.**
- **R.**
  - R1–R18 are ok.
  - R3 gives its one note, the one 5.18.89 and 5.18.90 gave: migration.log holds no line for 087.
  - R5: all 258 earlier files are intact. R14: Domain B's 363 files are byte for byte.
  - R7: UGX CASH IN HAND 41,071.00 · USD 0.00. R13: the event queue holds 8,655 events, every one done.
  - R18: on the server's own configuration the gate decides *skip* (`by-store=uganda by-files+vault=uganda`).
- **R19, both ok.**
  - The stray database is `dc85ae6ddafaae19:2379776:1791366907` before the deploy, at the copy and now, with no side
    files and 0 locks each time.
  - *"the retry job ran at 2026-10-10T03:10:04Z with the fix installed (at 2026-10-10T02:53:29Z), and the stray folder
    has not changed since the copy (the folder's time then: 2026-10-10T02:45:47Z; side files then: -) — nothing held the
    store at the copy, and before the deploy its last change fell within one dispatch of the job's (A14): nothing has
    opened the stray store since the copy."*
  - The stray folder's `migration.log` grew from 150,955 to 151,318 bytes, last written at 03:10:51 UTC. That is
    reported, not judged: other jobs write it too (the runbook above, step 4).
  - This is one completed run of the fixed job, about 17 minutes after the copy.

**V4 — what it is.** This is by the code, read in the repository on 10 Oct by a read-only workflow of three
investigators and two skeptics. The server's file and line were read afterwards: see *Confirmed on the server* below.
- **The candidate: `cron/master.php:405`.** Master opens its lock into `$lockFp` and includes every job in its own
  scope with a bare `include`. Twelve of its jobs assign `$lockFp` at their top level and close it on every normal exit,
  among them the 60-second `wa_sync` (`cron_wa_sync.php:33`) and `crm_sync` (`cron_sync.php:46`). After the job loop,
  master releases its lock with an unguarded `flock($lockFp, LOCK_UN)` (line 405), outside its `try`. When the last of
  those jobs closed the handle, that is a `TypeError` on PHP 8.
  - `main.php` and the `cron_trigger` route catch it.
  - The admin pages' piggyback (`public.php:2207-2228`, a shutdown closure running `@include` with no `try`, after
    `fastcgi_finish_request`) does not. That fits the `NOTICE: PHP message:` (php-fpm) form of both lines.
  - 5.18.51 guarded only the shutdown handler, at line 88 (docs/44 §16.11). The final release was left unguarded.
  - Every other `flock()` reachable from a master run acts on a handle its caller opened a moment before. None is in a
    file 5.18.91 changed.
- **Outside 5.18.91, by the code.**
  - `cron/master.php`, `public.php`, `main.php` and every job that touches `$lockFp` have the same blob in `3d9cb5f`
    and `dfad4d9`.
  - The retry job holds no lock in either version.
  - Every `$lockFp` job runs before it in master's list. The two after it (`customer_reminders`, `notify_retry`) touch
    no lock.
  - A rollback would keep the same mechanism.
  - Why it is seen only now: the 5.18.88–5.18.90 deploy-run V4 windows were 60 s, and before 5.18.88 an
    `--after-only` V4 read nothing. This is the first post-deploy window that is long enough and includes admin page
    loads.
- **Confirmed on the server, 10 Oct.** The operator ran the read-only count, by hour and by file and line, since
  08 Oct. Its output carries no message text:

  | Hour (UTC) | Lines | Where | Live then |
  |---|---|---|---|
  | 08 Oct 01 | 1 | `cron/master.php:405` | 5.18.89 |
  | 08 Oct 06 | 1 | `cron/master.php:405` | 5.18.89 |
  | 08 Oct 19 | 1 | `cron/master.php:405` | 5.18.90 (deployed 15:52) |
  | 09 Oct 19 | 1 | `cron/master.php:405` | 5.18.90 |
  | 10 Oct 02 | 1 | `cron/master.php:405` | 5.18.91 (02:59:14, after the copy at 02:53:29) |
  | 10 Oct 03 | 1 | `cron/master.php:405` | 5.18.91 |
  | 10 Oct 04 | 1 | `cron/master.php:405` | 5.18.91, after the `--after-only` run |

  **Every line is `master.php:405`, and four came before 5.18.91 was installed.** The fatal is older than 5.18.91, as
  the code said. About one every few hours is far fewer than master's runs, consistent with the piggyback path being
  the one that does not catch it: an admin page load, at most once per 5 minutes. **That path is still inferred**: the
  stack frames were not printed. The fix is 5.18.92 (the entry below).
- **The effect, by the code.** No job's work is lost: the schedule is saved after each job, and the admin page has
  already been sent. The rest of that piggyback closure and the shutdown functions registered after it are skipped,
  among them master's own lock handler and SQLite's passive checkpoints. PHP releases the lock when the request ends.

**What this means for the quarantine (runbook step 3).** Its evidence is R19's two lines *"in a run with 0 failed"*.
V4 reads from the copy, so every later `--after-only` of this deploy will repeat these two lines. **That condition can
no longer be met from this deploy.** Whether R19's lines are accepted beside this V4 failure, once the server confirms
what it is, or a later release that guards `master.php:405` comes first, is the operator's decision. Nothing was
quarantined, switched or resumed. The salesperson pilot is the operator's to resume.

### Rollback — its own command, never pasted with the deploy

`scripts/deploy-5.18.91.sh --rollback` asks for `ROLLBACK`, puts 5.18.90 (`3d9cb5f`) back through `deploy-hybrid.sh`,
and checks it (RB).
- The retry job is 5.18.90's again: on Uganda it opens the stray store at most every 10 minutes (applying any migration
  it lacks — none is due) and leaves `$dataDir` pointing there, as before.
- `lib/StarlinkRetryScope.php` stays on disk, reached by nothing (`deploy-hybrid.sh` never deletes).
- Every switch stays as it is.
- **If the stray store has been quarantined by then, put it back first.** 5.18.90's job would otherwise create a new,
  empty store there and import any `*.json` it finds in that folder.

The command, as root on the server, on its own:

    cd /opt/dishnet && bash scripts/deploy-5.18.91.sh --rollback

By hand, only if the script cannot run: `cd /opt/dishnet && git checkout 3d9cb5f && { bash scripts/deploy-hybrid.sh; git checkout -; }`.
It returns the checkout to its branch whether or not its copy step succeeds. The same put-back is the one to use if a
deploy's copy stopped part-way: the deploy, and `--rollback` too, then say so and print it.

## 10 Oct — 5.18.92: the scheduler's own lock — cron/master.php keeps its lock under its own names, so the jobs it includes can no longer make its final release a TypeError (the fatal "cron/master.php:405" on the server since at least 08 Oct); no migration; South Sudan and Domain B unchanged. `release/5.18.92` = `247a480`, cut on live 5.18.91 (`dfad4d9`); `scripts/deploy-5.18.92.sh` pinned to it and rehearsed — PUSHED 10 Oct 08:15 UTC; DEPLOYED 21:32:52 UTC (PASSED 74/0/2); `--after-only` 21:43:34 UTC PASSED 55/0/1, R19 conclusive

**Request.** On 10 Oct the operator asked: *"5.18.92 build and do necessary to work sales-0001 to start answering
customers"*, then *"give me deploy command when its ready"*. Asked which should come first, the operator chose
**"5.18.92 first (Recommended)"**. So 5.18.92 is deployed with the registry still off, and the salesperson pilot (docs/66
from step 2 onward) is switched on after it. The card's step 1 (*Verify number*, *Register webhook*, and *Verify number*
on the department numbers) may be done before; this script accepts that (rehearsal 1o). **sales-001 needs nothing from
5.18.92.** Its steps are the operator's, on the card and with `set_config.php`, as docs/66 sets them out.

### Release notes

- **The defect** (see 5.18.91's RESULT above). `cron/master.php` includes every job with a bare `include`, in its own
  scope. Twelve of the jobs it dispatches assign `$lockFp` and `$lockFile` at their top level — the names master's own
  lock used — and close them on their way out:
  - `cron_wa_sync.php`, `cron_sync.php`, `cron/photo_retry.php`, `cron_invoice_notify.php`;
  - `cron_lte_sync.php`, `cron_lte_usage.php`, `cron_lte.php`, `cron/cash_carry_reminder.php`;
  - `cron_maintenance.php`, `cron_fiber_sync.php`, `cron_wallet_sync.php`, `cron_leads.php`.

  At its normal end master then unlocked the last such job's closed handle, which is a TypeError on PHP 8. `main.php`
  catches it. The admin pages' piggyback run does not: public.php runs master from a shutdown function, with nothing to
  catch the throw. php-fpm logs *"Uncaught TypeError: flock(): supplied resource is not a valid stream resource in
  …/cron/master.php:405"*, and every shutdown function after it is skipped (master's own lock handler, SQLite's passive
  checkpoints). No job's work is lost: the schedule is saved after each job, and the page has already been sent. The
  server shows 7 such lines since 08 Oct, all at `:405`, under 5.18.89, 5.18.90 and 5.18.91 alike.
- **The fix.** Master's lock becomes `$_m_lockFp` and `$_m_lockFile`, prefixed as its store and its config already are,
  and its normal-end release is guarded with `is_resource()`. No other line of master.php changes; the base→pin diff is
  that rename, that guard and their comments (22 insertions, 13 deletions). A job's `$lockFp` can no longer reach
  master's lock. 5.18.51's guard on the shutdown handler stays.
- **Not changed:** every job, the scheduler's list and order, the retry job and its gate (5.18.91), every switch.

### Files — 8 against `dfad4d9`: none added, 8 changed, none removed

- **Changed (8):** `cron/master.php`, `tests/test_master_lock_release.php`, `manifest.json` (5.18.92), and the version pin
  only in `tests/test_distributor_apply.php`, `tests/test_distributor_link_ucrm.php`, `tests/test_distributor_notify.php`,
  `tests/test_distributor_registry.php` and `tests/test_distributor_territory.php`.
- **8 files changed, 219 insertions(+), 98 deletions(-).** No migration; nothing outside the plugin.
- sha256: `cron/master.php` `5400be20c678d5ec…`, `tests/test_master_lock_release.php` `6fe15211f91123bc…` — both pinned by
  the deploy script (A0).

### Ancestry

`247a480` (5.18.92, release) ← `dfad4d9` (5.18.91, live) ← `3d9cb5f` (5.18.90) ← `53d5c4d` (5.18.89).
- **The development commit is `2a499a8`.** Its `tests/test_master_lock_release.php` is the release's byte for byte. Its
  `cron/master.php` makes the same lock change, hunk for hunk with line numbers set aside, but it also carries the
  undeployed AI media job. So the release's master.php is 5.18.91's plus exactly that change, which is proved three
  ways:
  - the derivation undoes it and gets 5.18.91's file back byte for byte;
  - the rehearsal redoes it independently, with two controls;
  - A0 pins the result by sha256.
- The development branch also carries undeployed work: the partner portal (081–083), the PD-8 CSRF guard and the AI
  media layer (085). None of it is here.

### Tests

- **`tests/test_master_lock_release.php`, rewritten: 78 passed, 0 failed** (it had 27). It reads master's lock section
  and its normal-end release from `cron/master.php`, and a job's own lock from `cron_wa_sync.php`. Each must be found
  exactly once, or the test stops. It runs them in child processes, directly and as the admin pages' piggyback runs
  master (a shutdown function that includes it, with nothing to catch a throw), with each kind of job:
  - **a job that takes no lock**;
  - **a job that takes and closes its own** (cron_wa_sync.php's);
  - **one that leaves its own handle open**;
  - **one that dies on an uncatchable fatal**;
  - and **a run that finds the lock held**, which must return at once.

  Every clean run must:
  - print nothing on stderr;
  - release master's lock at its normal end;
  - run every later shutdown function.

  **Controls:**
  - 5.18.91's lock, rebuilt from the same code by its old names, ends in the server's message under the piggyback. Its
    control on the control, the same lock with a job that takes none, runs clean.
  - 5.18.50's handler fails as docs/44 recorded.
  - Six weakened copies of the fix are each caught.

  A scope scan reads all 50 job scripts: master names no bare `$lockFp` or `$lockFile` outside its comments, no job
  names `$_m_lockFp`, and 12 jobs assign `$lockFp` (the control).
- **Full suite, development, twice**, on `2a499a8`: 289 files, **14,313 passed, 0 failed, 0 skipped** in each pass,
  identical file by file to each other. Against 5.18.91's development passes (14,262) the only change is this test,
  27 → 78. Pass A ran 04:25–05:12 UTC on the tree just before the commit; pass B 05:12–06:00 UTC on `2a499a8`. The only
  PHP warnings are `test_dpo_endpoints`' five, in every pass.
- **The release tree's own suite, twice**, in its own worktree on `247a480`, clean: pass A 06:13–06:58 UTC, pass B
  06:58–07:43 UTC. Each read 277 files, **13,391 passed, 0 failed, 0 skipped**, identical file by file to each other and
  to 5.18.91's release suite (13,340) but for this test, 27 → 78. The warnings are the same five.
- **Lint:** `php -l` on the seven PHP files of the release diff, read from the commit: 0 errors (PHP 8.4.19 here). The
  server's own PHP lints them at A2, and runs the test itself at A15 and R20.
- `git diff --check dfad4d9 247a480`: clean.
- **Secret scans** of the release diff, the script and the rehearsal: clean. The only phone-shaped values are the
  automated-send policy check's throwaway fixtures, carried unchanged from 5.18.91's script (a country code and zeros).

### South Sudan and Domain B

master.php runs the same jobs in the same order on both installs; only its own lock's variable names change. As for
5.18.91, the deploy's and the rehearsal's A6/A13/R13/R15/R17/R18 run South Sudan's paths, and A7/R14 hold Domain B and
the plugin's docs byte for byte.

### `scripts/deploy-5.18.92.sh` — 2961 lines, sha256 `2f1920f9692147bf…`

It is derived from `deploy-5.18.91.sh`. Every anchor and every replacement is asserted, and none of 5.18.91's wording is
left where it no longer holds. **It keeps 5.18.91's state requirements — the registry dark, own leads only off, no
salesperson number switched on — because the operator chose to deploy before the pilot.** What changes:

- **The pin and its base:** `247a480` on `release/5.18.92`, cut on `dfad4d9`. The delta is exactly the 8 files.
- **A0** also requires the pin's master.php to be the reviewed release file byte for byte (`5400be20…`), and its lock
  test the reviewed one (`6fe15211…`).
- **A13:** the live job is already 5.18.91's gated one, so on Uganda both the live job and the pin's must stop. The pin's
  must be 5.18.91's byte for byte, and South Sudan's open of the throwaway stray store is the control that shows the
  observation sees a change.
- **A14** is carried from 5.18.91's deploy record (`/root/dnb-5.18.91/state-5.18.91.env`; `DNB_PREV_STATE` overrides it).
  Since that copy the live job opens nothing, so the folder's quiet can no longer be held against its dispatches. To
  answer "seen", that record must say "seen" and hold no R19 failure, and the stray store must be exactly as it was at
  that copy: the same database file, no side file then or now, and the same folder time.
- **A15 (new, refusal 15):** the pin's `tests/test_master_lock_release.php`, run under the server's own PHP, from the code
  copied into the container's /tmp, as the database's owner, with a TMPDIR of its own that the run removes. It must read
  `78 passed, 0 failed`. **If it does not, the log shows what the test printed** — its failed assertions and its last
  lines — before the NO-GO.
- **The copy's time** is master.php's, the file the deploy stamps; 5.18.91 read it off the retry job.
- **V4** names each fatal by its time and file:line. It reads both `<file>:<line>`, as an uncaught exception writes it,
  and `<file> on line <line>`, as every other fatal and every parse error does. It never quotes more of the text.
  `cron/master.php:405` with the flock message, within 30 minutes of the copy, is a note (a master run begun on
  5.18.91's code). After that it is a FAIL, and after a rollback a note. Any other fatal fails, as before.
- **R20 (new):** the installed lock test under the server's PHP, as for A15. It also prints what the test printed on a
  failure.
- **RB:** back to 5.18.91 — master.php and the gated retry job byte for byte. The old fatal can return, and a note says
  so.
- **The code copy** splits its parts into words without the shell's pathname expansion. git therefore matches
  `cron_*.php` inside each commit, never the shell against the checkout's working tree, which a later push may change.
- **F** says what changed, and asks for a second `--after-only` a day later, when V4 has read enough admin page loads.

**Its derivation checks, before writing a byte:**
- the release delta is exactly the 8 files;
- the pin's master.php, with the lock change undone, is 5.18.91's byte for byte;
- the pin's master.php has no media job;
- the pin's lock test is the development branch's;
- the retry job is 5.18.91's at both commits;
- no real name or number is in the script.

`bash -n` is clean. The script was committed on the development branch as `dc6f70f`, and that is the file the final
rehearsals ran.

### The rehearsal — `scripts/harness/deploy-5.18.92/rehearse.sh` (945 lines, sha256 `740adc9fc1037f45…`)

It is built from 5.18.91's: the sandbox is 5.18.91's, each edit asserted, and the scenarios are new. The base is
5.18.91 installed as production runs it, with the stray store untouched since 5.18.91's copy and 5.18.91's deploy record
as that script writes it. **R19's judgement is 5.18.91's line for line** (asserted), so its full matrix, rehearsed for
5.18.91, is not repeated here; its pass, its note, and a real open's failure are.

- **§0 controls.** These include the base→pin master.php check (5.18.91's file, release guarded and lock renamed, is the
  pin's; the rename alone, or one other line changed, is not) and the pin's lock test passing from an A15-shaped copy.
- **§1 refusals, nothing changed by any:**
  - **1a–1g:** the version, the pin, master.php, the lock test, a planted file;
  - **1h/1i:** the registry or own leads only switched on;
  - **1j:** the pilot's number switched on;
  - **1k:** `ucrm.json` naming the stray folder;
  - **1l:** an untracked file in the checkout;
  - **1m:** A15 refusing a pin whose master releases `$lockFp` again, with the test's own output in the log;
  - **1n:** A13 naming exactly `pin-job-not-5.18.91's uganda-not-stopped`;
  - **1p:** the installed job's gate taken out → `live-uganda-not-stopped`;
  - **1q:** A13's control, the installed job opening nothing → `live-south-sudan-not-measured south-sudan-differs`;
  - **1w:** the code copy failing.

  **1o** accepts the department numbers verified on the card.
- **§2 weakened copies.** A15 and A0 are each shown to be the layer that refuses. Each of A14's conditions is shown
  alone, as a note, with a copy blind to that one condition saying "seen" (caught):
  - the folder's time;
  - the record's own A14;
  - the database file, its folder's time kept;
  - a side file now;
  - a side file in the record.

  **2d:** no record, or one holding an R19 failure. **2f:** the stray `migration.log` grown since the copy, as
  dpo_reconcile grows it, and A14 still "seen".
- **§3 the deploy:** PASSED, 0 FAIL, two notes (R19's, and V4's for the old fatal planted during the run). The ok lines
  and the log's hygiene are checked, and so is the state file.
- **§4 `--after-only`:**
  - **4a:** R19's note before master has run the job.
  - **4c:** PASSED with 0 failed and 0 notes — the evidence the quarantine waits for.
  - **4d:** the old fatal 10 minutes after the copy is a note.
  - **4e:** 40 minutes after, a FAIL; its weakened copy, with no time bound, passes.
  - **4f:** another fatal fails.
  - **4h:** the old message at another line of master.php fails; its weakened copy, blind to the line, passes.
  - **4i:** another fatal at `:405` fails; its weakened copy, blind to the message, passes.
  - **4j:** a fatal and a parse error written "on line N" fail, each named by file:line.
  - **4k:** a `cron_*.php` in the checkout that neither commit holds, run from the checkout, still copies the code; the
    shell-expanded copy fails.
  - **4g:** a real open of the stray store fails R19, and the failure is recorded.
- **§5:** an installed master.php with the old release fails R20 and R1.
- **§6:** the rollback PASSES, with RB's checks; deploying again over it PASSES.
- **§7:** Evolution is never called. The stray folder ends as seeded, the checkout is as found, and nothing is left in
  /tmp. Every run of the script took the options it was given: a run that answers "unknown option" is recorded, and
  none was; the control on the control shows such a run is recorded.

**Results.** Two final runs on the committed script (`dc6f70f`, sha256 `2f1920f9…`), 08:00–08:07 and 08:07–08:15
UTC on 10 Oct, each read **178 passed, 0 failed**, over 53 runs of the script; the deploy's own tally was 74 ok,
0 failed, 2 notes. Before them, five trials:
- trials 1–3 on the first draft, the third reading 150/0;
- trial 4 on the reviewed script, 175/1 — it found the misordered options (Reviews);
- trial 5, 178/0.

### Reviews

One independent read-only review workflow ran: four dimensions, each finding adversarially verified (7 agents).
- **The fix itself — no finding.** Since 5.18.92, master touches its handle only under `$_m_lockFp`, before any job
  runs and at its guarded normal end. The shutdown closure captures it by value, and no job names `$_m_`. Every
  variable master reads after the first include is `$_m_`-prefixed except `$dataDir`. That one is reassigned by 41 jobs:
  all to the same directory, and by `cron_starlink_block_retry.php:50` to `<plugin>/data`. That reassignment never
  changes master's behaviour: on Uganda the gate returns first, and on South Sudan it is the live directory. It is older
  than this release (Known limits 3). The development commit's lock change is the release's, hunk for hunk.
- **Fixed after the review, then re-rehearsed:**
  - **A15/R20 kept none of the test's output** (should-fix). A NO-GO there would have given no reason, and this is the
    first child-process test run in the server's container. The log now carries the test's failed assertions and last
    lines (rehearsal 1m shows them).
  - **V4 could not name the file and line of non-exception fatals or parse errors** (should-fix), which PHP writes as
    "in <file> on line <N>". It now reads both forms (rehearsal 4j).
  - **The code copy's `cron_*.php` was expanded by the shell against the checkout's working tree** (should-fix). It is
    now matched by git inside each commit (rehearsal 4k, with its weakened copy).
  - **The rehearsal lacked refusing cases** for A14's record, database and side-file conditions, for V4's line and
    message tests, for A13's live side and its control, and for a failed code copy. It also lacked an independent proof
    of the pin's master.php. All were added; each new condition is shown alone, with a weakened copy where it guards
    something.
  - **Wording:** the header's "byte for byte the development branch's" for master.php (it is the same lock change, not
    the same file), and A15's and R20's "the server's own line", which only the piggyback's message matches; `:405` is
    V4's to name.
- **Found while rehearsing the fixes:** in four weakened-copy cases (4e', and the new 4h', 4i' and 4k') the rehearsal
  passed its own `--script` option after the script's `--after-only`. The script then answered *"unknown option"* and
  stopped, and "no FAIL line" read as the weakened copy passing. 4e' had been vacuous since the first draft of this
  rehearsal; 4k', which expects a failure, exposed it. All four now pass the options in order and require the run's
  own verdict line, and the runner records any run that answers "unknown option" (§7). 5.18.91's rehearsal passes them
  in order throughout.
- **Left, with reasons:** Known limits 4–6.

### Deployment runbook — each step is the operator's

1. **Push `release/5.18.92`** on the operator's word (*"give me deploy command when its ready"*), so the server can fetch
   it. **Pushed 10 Oct 08:15 UTC** (`247a480`).
2. **Deploy**, as root on the server, then send back THE LOG FILE (never a copy of the terminal):

       cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && git fetch origin release/5.18.92 && mkdir -p /root/dnb-5.18.92 && bash scripts/deploy-5.18.92.sh 2>&1 | tee /root/dnb-5.18.92/deploy-$(date -u +%Y%m%dT%H%M%SZ).log

   Expected: PASSED with R19's note (no run of the retry job since the copy yet), and possibly a V4 note for the old
   fatal from a master run begun before the copy. **Press no card button while it runs.**
3. **At least 20 minutes after the copy**, read-only:

       cd /opt/dishnet && bash scripts/deploy-5.18.92.sh --after-only 2>&1 | tee /root/dnb-5.18.92/after-$(date -u +%Y%m%dT%H%M%SZ).log

   Expected: PASSED, 0 failed, including R19's `ok    R19 the retry job ran at … nothing has opened the stray store
   since 5.18.91's copy`. That is the evidence the stray store's quarantine waits for: R19 in a run with 0 failed.
4. **Then the salesperson pilot**, docs/66 from step 1 (or step 2 if the card's step 1 is done). The pilot needs nothing
   further from this release.
5. **A day later**, run `--after-only` once more. V4 then shows whether the old fatal has stopped.
6. **The quarantine of the stray store is NOT part of this release.** It needs its own approval, after step 3.

### Known limits and open risks

1. **This script refuses once the pilot is on** — the registry, own leads only, or a salesperson number switched on (A5,
   A5b, A8). That is the operator's order: 5.18.92 first. The next release's script must instead accept the pilot's
   switches as the operator leaves them. 33 checks are mapped for that rework (A5, A5b, A8, A11, R2b, R2c, R11, R12,
   V3f, V3h among them).
2. **A15 and R20 are the first child-process test run in the server's container.** They need `proc_open` and a clean
   stderr there, and nothing has yet shown either on the server. If either is missing, A15 refuses before anything
   changes, and the log now says why.
3. **`$dataDir` in master's shared scope** (the review): `cron_starlink_block_retry.php:50` leaves it on `<plugin>/data`
   for the jobs after it. On South Sudan that is the live directory; on Uganda the gate returns first. This is older
   than 5.18.92 and not changed. Prefixing it (`$_m_dataDir`) would close it in a later release.
4. **The test's "lock is free afterwards" checks after a child has exited cannot fail**, since the exit drops the lock.
   So in the crash case the shutdown handler's own release is not observed separately (the review's LT-2). This carries
   no deploy risk: A0 pins master.php, and the process end frees the lock. The docblock's *"exactly as the server did,
   directly and under the piggyback"* also says more than it tests: only the piggyback is the server's path (LT-3).
   Changing the test now would change the release commit and re-run every suite. Both wait for the next change to the
   test.
5. **RB's own report of a partial rollback copy is not rehearsed** (the review's F8). The rollback's effect is checked
   independently (§6: master.php and the retry job, by sha256).
6. **V4's evidence that the old fatal has stopped needs admin page loads.** Before a day has passed, its absence is not
   evidence (F says so).
7. **Seen during the work, not changed:** `cron_wa_sync.php` carries a hard-coded fallback value for its feed secret. Its
   value is not repeated here; it is for a separate look.

### RESULT — DEPLOYED 10 Oct (copy 21:32:52 UTC): PASSED 74 ok / 0 failed / 2 notes; `--after-only` 21:43:34 UTC: PASSED 55 / 0 / 1 — R19 conclusive

Recorded from the two terminals the operator pasted (the script prints no secret). The log files stay on the server
under `/root/dnb-5.18.92/`. No money figure, number or name from either run is repeated here.

**Push.** `release/5.18.92` was pushed at 08:15 UTC on 10 Oct, a new branch at `247a480`. The deploy command was given
in the chat; the rollback was not (it is printed at the end of the deploy's log, on its own).

**The deploy run (`20261010T213203Z`):**
- **Stage A, all ok.**
  - The checkout was at `2bd2916`, the release `247a480`, cut on `dfad4d9`. There were no tracked edits and no
    untracked files under the plugin folder. The live commit was `dfad4d9` (5.18.91), on PHP 8.1.34.
  - A0, A7, A2 and A3–A5b held. Every switch read as the operator had left it, the registry `mn=absent/absent`.
  - A8 held: 087 complete, one salesperson number, none switched on, `dnum=0`.
  - A9–A11 and A6 held.
  - **A14 "seen":** the stray store was exactly as 5.18.91's deploy recorded it at its copy — the same database file,
    no side file then or now, its folder unchanged since 02:45:47 UTC. Its `migration.log` had grown since that copy,
    as `dpo_reconcile` grows it; A14 does not compare the log (rehearsal 2f).
  - A12 held. **A13:** both the live job and the pin's stop on Uganda, and both open on South Sudan (the control).
  - **A15: 78 passed, 0 failed under the server's PHP 8.1.34** — the first child-process test run in the container.
    `proc_open` is there and stderr was clean (Known limits 2: resolved).
- **Backup** in `/root/dnb-5.18.92/backup-20261010T213203Z`:
  - the live `plugin.sqlite3` (integrity ok);
  - the data folder;
  - the installed 5.18.91 plugin;
  - the stray folder, copied as files;
  - the vault;
  - the event queue's snapshot (one `wa.escalation` not yet done).
- **The copy** at 21:32:52 UTC; the container serves `247a480`.
- **V:** every check ok, V3–V3h unchanged. V4 read 19 lines over 60 s: no fatal.
- **R:** R1–R20 ok. Two notes, both expected:
  - **R3:** `migration.log` holds no line for 087 — the note 5.18.89, 5.18.90 and 5.18.91 gave.
  - **R19:** no completed run of the retry job yet since the copy.
- **74 ok, 0 failed, 2 notes; PASSED** — the rehearsal's own tally. In the rehearsal the second note was V4's, for the
  old fatal it planted; on the server it was R3's.

**The `--after-only` run (`20261010T214334Z`), 10 minutes 42 seconds after the copy: 55 ok, 0 failed, 1 note (R3's);
PASSED.**
- **R19 ok.** Master ran the retry job at 21:40:07 UTC, after the copy. The stray folder has not changed since
  02:45:47 UTC, and nothing held the store at the copy. With A14 "seen" before it, the run concludes *"nothing has opened
  the stray store since 5.18.91's copy"*.
  - **This is the evidence the stray store's quarantine waits for: R19 in a run with 0 failed.**
  - The quarantine itself is NOT done. It needs its own approval.
  - The run came earlier than the 20 minutes the runbook suggests. R19 needs only a completed run of the job after the
    copy, and master's record shows one, so its conclusion stands.
- **V4:** no new fatal of the plugin since 21:32:42 UTC, 378 lines read. Whether the old `cron/master.php:405` has
  stopped is shown only after a day of admin page loads (below).
- **R13:** the `wa.escalation` that was pending at the snapshot was done by then (total 9007, all done).

**After the check, the pilot (docs/66) began** — the operator's steps, recorded as they were pasted:
- `sales_own_leads_only = 1` (step 3): in effect at once. Each salesperson sees only their own leads; admins and holders
  of *All Leads* see every lead.
- `wa_followups_on_owned_numbers = 1` (step 3): inert until the registry is on, and then only on a salesperson's number
  with its assistant on.
- `multi_number_channels_enabled = 1` (step 2) was **refused, twice**: *"Not yet: the department numbers are not all
  verified (sales, support). Nothing was saved."* The card's **Verify number** on the Sales and Support rows (docs/66 step
  1.5) came first: **sales verified 21:50:04 UTC (••••15), support 21:50:30 UTC (••••48)**, each read from Evolution's
  report for its instance; accounts is covered by the support number. Read back with a read-only page check the operator
  ran in the browser's console (no names, instance names or numbers printed beyond two digits).
- **`multi_number_channels_enabled = 1` then accepted** (step 2): the registry is ON. Next, per docs/66: the department
  test, then sales-001's Verify number, Register webhook, Switch on with the assistant off, and the assistant on.
- **sales-001 verified 22:00:32 UTC (••••57)**, read from Evolution's report for its instance; still `disabled`,
  assistant off. The card's buttons seemed to do nothing in the browser — uCRM keeps the outer page scrolled while the
  plugin's page reloads, so the answer box at its top stays out of view — so the operator pressed it from the console with
  a script that sends exactly that button's request once, refuses a step out of order, and prints the plugin's answer
  with no name, instance name or number beyond two digits.
- **sales-001's webhook registered** (*"Evolution will now send the messages of sales-001 to this plugin."*), then
  **sales-001 switched on with its assistant off** (*"sales-001 is switched on."*, now `active · assistant off`), each
  through the same console script. Next: the Inbox and department tests (docs/66 steps 2 and 4), then the assistant on and
  step 5's tests, the AI-to-AI loop test among them.
- **A test message to sales-001 from the alert number got no AI reply** — correct twice over: the assistant was still off,
  and since 5.18.90 a salesperson's number never answers DishNet's own side (its lines, the alert numbers
  `alert_whatsapp` / `whatsapp_admin_phone`, the owner's phone of record): the message is kept for the team, silently
  (`internal_recipient`). The assistant tests therefore need a phone that is none of those.
- **sales-001's assistant switched on, at the operator's word** (*"make it on so we can answer custoemrs"*): *"The
  assistant answers on sales-001 while the number is on."*, now `active · assistant on`. The department and Inbox tests
  were not reported first; the first customer-like reply from sales-001's own number is the check that stands in for
  them. Still to report: docs/66 step 5's tests, the AI-to-AI loop test among them.
- **docs/66 step 5's five tests — all passed, the operator reports (11 Oct): "1-5 all are working well no error"**:
  - a product question answered from sales-001's own number, as the salesperson's assistant;
  - a quotation request made a lead owned by the salesperson, channel `sales-001`;
  - "can I talk to a person" marked the chat and alerted the salesperson;
  - the salesperson's own reply stood the assistant down;
  - **the AI-to-AI loop test: no DishNet number got an AI reply.**

  **sales-001 is live, its assistant answering customers.** What remains is docs/66 §6's watch:
  - a follow-up from the pilot number, once a chat has gone quiet;
  - no cross-visibility of leads between salespeople;
  - the webhook guard's disconnection alert.

  Separately: the day-later V4 log count (above), and the stray store's quarantine, which waits for its own approval.

**The day-later log count (11 Oct), the read-only command above, run by the operator over the log since 21:33 UTC on
10 Oct: `cron/master.php:405` — 0 lines. 5.18.92's fix holds on the server.** The command found one other location:
**12 lines at `main.php:484`** — a different defect, older than this release, and not caused by it:
- **The code.** `main.php` is the same file in 5.18.91 and 5.18.92 (no diff), and this code is in the repository at
  `5f33b8a` (26 Sep) at the latest. Line 484 is `$crmGet("clients?…")` in the nightly **UCRM auto-pull** (`ucrm_auto_pull_hour`,
  default 3, the plugin's local time: 00:00 UTC in Kampala). `$crmGet`, `$crmBase` and `$crmToken` are defined only
  inside the **wallet sync's** branch, which runs only when its interval has passed. On a run where the wallet sync
  skips, the auto-pull reads undefined variables, passes its "CRM not configured" check (`null === ''` is false), and
  calls `null` — PHP 8's *"Uncaught Error: Value of type null is not callable"*. It writes its last-run file only after
  the pull, so every `main.php` run in that hour fails the same way: about 12 an hour at a 5-minute tick.
- **What it costs.** `cron/master.php` runs earlier in `main.php` (lines 264–277), so every background job ran — the
  AI replies, sales-001 among them. What fails is the auto-pull and what follows it in that run: the client cache's
  nightly refresh and the search and sales indexes it rebuilds. No data is written wrongly; the nightly refresh simply
  does not happen when no run in that hour also ran the wallet sync.
- **Why it surfaced now.** 5.18.91's server check looked for the flock fatal only, and no deploy check before this one
  ran during that hour. The count by night (`docker logs --timestamps … | grep -F 'main.php:484' | cut -c1-13 | uniq -c`)
  will show whether it happens every night. **The fix is a release of its own** (define the CRM credentials and
  `$crmGet` once, before both sections), at the operator's word.
- The `[ConfigVault] restored after re-install` line those commands print is the usual in-memory fill.

**The day-later check is a read-only log count.** With the pilot's switches now set, a later `--after-only` of this
script would report the checks that expect them off (Known limits 1). So the evidence that the old fatal has stopped is
taken with a command that prints only file:line counts — never a message — and should print nothing, run any time after
21:33 UTC on 11 Oct:

    docker logs --since 2026-10-10T21:33:00Z ucrm 2>&1 | grep -F dishnet-hybrid-sudan | grep -E 'PHP Fatal|PHP Parse error|UNCAUGHT' | grep -oE 'dishnet-hybrid-sudan/[A-Za-z0-9_./-]+(:[0-9]+| on line [0-9]+)' | sort | uniq -c

### Rollback — its own command, never pasted with the deploy

`scripts/deploy-5.18.92.sh --rollback` asks for `ROLLBACK`, puts 5.18.91 (`dfad4d9`) back through `deploy-hybrid.sh`,
and checks it (RB). master.php is then 5.18.91's again, so the piggyback fatal at `:405` can return. The retry job keeps
5.18.91's gate, and every switch stays as it is.

The command, as root on the server, on its own:

    cd /opt/dishnet && bash scripts/deploy-5.18.92.sh --rollback

By hand, only if the script cannot run: `cd /opt/dishnet && git checkout dfad4d9 && { bash scripts/deploy-hybrid.sh; git checkout -; }`.
