# 55 — DishNet AI communication layer: architecture assessment and implementation plan (2026-10-04)

**Status: ASSESSMENT — read-only. Nothing in this document changes production. Customer-facing automation stays off until the
operator approves each batch separately. The weekly marketing e-mail is a DRAFT sent to one approval mailbox only.**

Brief: extend the DishNet AI / WhatsApp sales assistant to voice notes, images and documents, understand intent and lead
state, follow serious leads up by WhatsApp and e-mail without spamming anyone, and prepare one marketing e-mail draft a
week for human approval — on the EXISTING Evolution integration, the EXISTING AI, the EXISTING CRM/lead structures and the
EXISTING follow-up engine. This document is Part 15 of that brief: the inspection that comes before any code.

Method: four read-only inspections of this repository on 4 Oct 2026 (the Hybrid plugin's WhatsApp path, its AI brain
and lead structures, its follow-up engine and e-mail stack, and the sibling `dishnet-ai/` plugin plus the `n8n/`
workflow), each claim with a file and line; where a statement is inferred rather than read, it says so. No server was
touched; this session cannot reach it. Secrets are named by key, never by value.

## 0. The one-paragraph answer

Uganda's WhatsApp is answered today by the Hybrid plugin: Evolution → `public.php?page=evo_webhook` → `evo_webhook.php` →
the `ai.reply` queue → `workers/AiReplyWorker.php` → `lib/DishNetAiBrain.php` → Evolution (`docs/27`, `docs/40` §220–225:
1,795 sales replies and 365 support replies in a week on 5.18.43). It handles **text and location pins** only; a voice
note, image or document is stored as `[AUDIO]`/`[IMAGE]`/`[DOCUMENT]` with its type and **never reaches the AI**
(`evo_webhook.php:331-333`), and both Evolution webhooks are registered with `base64:false`
(`lib/EvolutionApiService.php:389`), so no media bytes arrive. The follow-up engine exists and is good
(`lib/FollowUpService.php`, `FollowUpPolicy.php`, `FollowUpEvaluator.php`, migration 070), but it is **silence-triggered
and WhatsApp-only**. E-mail is a plain synchronous SMTP sender with lifecycle templates and a read-only inbound-mail AI that
files drafts for a person to send; there is **no marketing or campaign code at all**. The sibling `dishnet-ai/` plugin is a
stale text-only fork that the repository shows no sign of being installed, and the `n8n/` bot is inactive (`"active":
false`) — it is the only place in the repository that already branches on audio/image/document, and it does so unsafely.
**Build everything on the Hybrid's live path, behind flags; extend, do not replace.** Two defects found on the way mean the
live AI's lead capture and uCRM lead sync have never worked; they are the first thing to fix.

## 1. Current architecture (what runs for Uganda)

```
WhatsApp customer
   │  Evolution API (three instances → channels sales / support / account; manifest.json:57-71)
   ▼
public.php?page=evo_webhook → evo_webhook.php      token-guarded (EvoWebhookGuard), POST only, body limit, 15-min age cut
   │   stores the message (ConversationService::importEvoMessage → wa_conversations / wa_messages)
   │   records STOP-word opt-outs on inbound (ContactOptOut, scope proactive)           evo_webhook.php:300-328
   │   location pins handled                                                            evo_webhook.php:227-251
   │   media without caption → "[AUDIO]" etc., media_type kept, NOT passed to the AI    evo_webhook.php:331-333
   │   queues ai.reply on the EventBus (events table: pending→processing→done/failed→dead, retries, locks)
   ▼
workers/AiReplyWorker.php   (run_worker.php; cron/master.php:185-189 safety net; one worker at a time by file lock)
   │   stands down while a colleague is active / cooldown (wa_human_cooldown_minutes)   AiReplyWorker:1246-1311
   │   builds context per channel (products, services, balance only when identity is certain)
   │   last 20 turns × 400 chars                                                        DishNetAiBrain:1678-1707
   ▼
lib/DishNetAiBrain.php     Claude (default claude-haiku-4-5) or OpenAI (gpt-4o-mini); curl, 40 s, no retry, 600 tokens
   │   text markers, not function calling: ESCALATE · QUOTE · FLYER · LEAD{json} · PHOTO · DOC   :31-36, :1857-1927
   ▼
AiReplyWorker::guardReply  ReplyPrivacyGuard (secrets, foreign identifiers, amounts not permitted, prompt leaks) → fails
   │   closed: safe fallback + escalation + ai_security_events.json                     :685-765
   │   PlanFenceGuard, KitTaxNote appended after the check
   ▼
Evolution sendText          the point of no return; hand-over → wa_conversations.state = needs_human, wa.escalation, staff alert
```

Facts that bind the design:
- **Idempotency already has two layers on the live path**: `evo_webhook_seen` (UNIQUE on `message_id`, claimed before any
  side effect, pruned after 72 h; our own outbound ids are claimed there too — `EvoWebhookGuard.php:163-236`,
  `AiReplyWorker.php:1349-1364`) and a partial UNIQUE index on `wa_messages.wa_message_id` with a null-return in
  `storeMessage` (`ConversationService.php:190, 742-748`). A duplicate webhook delivery is dropped at the first layer.
- **A media-only message stops at the webhook**: stored with a placeholder body (`[AUDIO]`, `[IMAGE]`, …), **the AI is not
  queued**, there is one `error_log` line and no acknowledgement to the customer (`evo_webhook.php:336-341`,
  `ConversationService.php:1188-1190`); staff learn of it only from the watchdog, 10 minutes later, 07:00–21:00
  (`cron/wa_watchdog.php:49-88`). A captioned image or document sends the caption alone — the `ai.reply` payload has no
  modality field (`evo_webhook.php:344-359`). Wrapper types (`documentWithCaptionMessage`, `ephemeralMessage`,
  `viewOnceMessage`), reactions and contacts come out empty and are not stored, while the log says *stored*
  (`ConversationService.php:1199`).
- **Nothing is kept to fetch media later** — no mimetype, file name, URL/directPath or mediaKey (`ConversationService.php:
  1211-1225`) — but the event already carries `wa_message_id`, `remote_jid` and the instance (`evo_webhook.php:350-355`),
  which is what Evolution's `getBase64FromMediaMessage` needs. `EvolutionApiService` has only send and find endpoints
  (:415-517). Turning webhook `base64` on instead would collide with the 512 KB body cap (`evo_webhook.php:78`).
- **One worker, one lock, no debounce**: `WorkerBase` serialises all AI replies with a file lock (:62-65, :210-221); each
  event is claimed atomically (`EventBus.php:147-213`); retries back off 10 s → 2 h over five attempts, then escalate
  (`EventBus.php:257`, `AiReplyWorker:1376-1386`). A slow transcription inside this worker would slow every customer.
  New event types must be added to `event_processor`'s protected list or they are acked as unknown (`event_processor` :238).
- **Switches**: `ai_enabled` is the AI's master switch (`run_worker.php:33`) — while it is off the webhook still stores and
  queues, and the backlog is answered when it is switched on, with no age limit. `dry_run_mode` covers NotificationService
  only; AI sends bypass it (`NotificationService.php:153, 2130-2134`). There is no rate limit on AI sends and no business
  hours for AI replies (hours apply to follow-ups, the watchdog and invoice notices only).
- **STOP is detected on text only** (`ContactOptOut.php:233-250`), and the AI still answers a STOP message (`evo_webhook.php:
  88-107`); the reply guard permits any amount the customer "said" (`AiReplyWorker.php:947-956`) — text injected from a
  transcript or an image widens what it permits unless marked.
- **The legacy WASender path is where the only media handling lives** (`wa_webhook.php`, `WaInbound::normalise` with
  mimetype/filename/caption/url, `WaMessageProcessor` acknowledgements per type) and the live path never calls it;
  `cron_wa_sync.php:176` even uses `WaInbound` before requiring it at :195, so every row throws while the cursor advances.
  The repository's only multimodal design (`UGANDA-AI-CONVERGENCE-ARCHITECTURE-REVIEW.md:334-372`) sits on that dead path.
- **Two deployed numbers, one configuration**: `dishnet_richard` and `dishnet_ug` mapped to channels in configuration only;
  which is "sales" is not settled in the documents (gap analysis :148–149 vs `docs/27`:142–143). Read it from the live
  config before relying on it.
- **The webhook guard re-registers any foreign webhook URL** on the mapped instances within ~10 minutes
  (`cron/wa_webhook_guard.php:17-22,112-123`). Pointing an instance at n8n or another plugin is undone automatically.
- **No function calling** in the live brain; actions are text markers parsed after the model answers. New structured output
  (intent, stage) can ride the same channel.
- **Conversation state** is `bot_active | human_active | needs_human` (`lib/ConversationService.php:86`) plus identity
  states identified / anonymous / unknown / ambiguous (:463-466). There is **no per-turn intent, stage or next action**.
- **The tenant profile is not in the prompt**; Uganda is Starlink-only by `BrainContext` (:34-44) and the AI does not route
  Fiber or Data Network at all — the routing to preserve is Business vs Residential (public IP → Business; Residential
  default; the Mini kit first) and the per-number channel roles (`DishNetAiBrain.php:860-959, 1027-1131`,
  `PlanCatalogue`, `PlanFenceGuard`, the unlimited-data fact).
- **Prices come from uCRM live** (`DishNetTools::getProducts`, 60 s cache); the prompt carries no price of its own. A
  second price source is the failure the project refuses (`PlanFenceGuard.php:40-44`, `set_config.php` warnings).

## 2. Existing WhatsApp / Evolution capabilities

| Capability | State | Evidence |
|---|---|---|
| Inbound text, extended text, captions, button/list replies | handled | `evo_webhook.php` type parsing (see §1) |
| Inbound location pin | handled; the pin is looked up, never believed from the model | `evo_webhook.php:227-251`, `AiReplyWorker::latestPin` :800 |
| Inbound audio / image / document / sticker | stored as a type marker, not processed, not sent to the AI | `evo_webhook.php:331-333` |
| Media bytes in the webhook | **off** (`base64:false` at registration) | `lib/EvolutionApiService.php:389` |
| Media download by API (`getBase64FromMediaMessage`) | **not implemented anywhere in the Hybrid** | grep: 0 hits |
| Duplicate webhook delivery | dropped: `evo_webhook_seen` claimed before any side effect (UNIQUE `message_id`, 72 h prune) + partial UNIQUE on `wa_messages.wa_message_id` | `EvoWebhookGuard.php:163-236`; `ConversationService.php:190, 742-748` |
| Outbound text | `EvolutionApiService::sendText`, re-checks opt-out inside | `EvolutionApiService.php:103, 415` |
| Outbound media | helpers exist (sendMedia/sendImage/sendDocument); the worker sends PHOTO/DOC/FLYER markers | `AiReplyWorker:229-240` |
| Human cooldown / take-over | the AI waits while a colleague is active | `AiReplyWorker:1246-1311`, `api_whatsapp.php:251` |
| Opt-out | `contact_optouts` (reasons incl. `not_interested`, `bounce`; sources incl. `ai_classified`); only STOP words write today, on messages ≤ 40 chars; the AI still answers the STOP message | migration 069; `ContactOptOut.php:45-56, 233-251`; `evo_webhook.php:88-107` |
| Escalation | `needs_human` + alert to `alert_whatsapp` (30-min cooldown) + `ai_handover_message` once; `wa.escalation` has no consumer; `needs_human` does not pause later AI replies (only `human_active` does) | `AiReplyWorker:1404-1464, 1252`; `event_processor` :243-247 |
| Business hours | follow-ups only: 08:00–20:00 local, never Sunday, `timezone` key (Africa/Juba if unset — Uganda must set Kampala) | `FollowUpPolicy.php:53-54, 182-206`; `lib/timezone.php:43-68` |

## 3. Existing AI pipeline (what to reuse, what not to touch)

Reuse: the marker channel and the LEAD JSON walker (`DishNetAiBrain.php:1817-1848`); `AiLeadService`'s two-gate pattern
(the model proposes, a fixed rule decides; merges never overwrite; system-supplied location; audit to
`ai_crm_actions.json`); `BrainContext`'s allow-list of twelve context keys (a new key must be added deliberately);
`ReplyPrivacyGuard` and `permittedAmounts`; the `promptPreview` seam and the frozen South Sudan prompt fixture
(`tests/fixtures/ai_prompt_golden_south_sudan.json`) that pins the other country.

Do not touch: `DishNetAiBrain`'s rules 1–7, the channel roles, qualification, PlanCatalogue/PlanFenceGuard/KitTaxNote.
Multimodal input must arrive as **text the brain already understands** (a transcript, an image description, a document
summary), inside the existing context, so none of this moves.

Three defects found on the live lead path (verified by reading the code; **fixed in Batch 0, release 5.18.75 on the
branch, proved by `tests/test_lead_path_batch0.php` — NOT deployed; see `docs/07`, 4 Oct, "Batch 0"**):
- **(a) WhatsApp lead capture never runs.** `AiReplyWorker.php:257-258` calls `$this->latestPin($convId, $ctx)`; `$ctx`
  is undefined in `handle()` (the variable is `$context`), so PHP passes null to an `array` parameter, throws, and the
  catch at :275 logs *lead capture failed*. `AiLeadService::capture()` is never reached; `crm.lead.sync` is never emitted.
- **(b) uCRM lead sync drops every event.** `UcrmLeadWorker.php:28` reads `$event['payload']` as an array; `WorkerBase`
  leaves `payload` a JSON string and decodes into `_payload` (`WorkerBase.php:117-118`). `lead_id` is 0 and the event is
  acknowledged as *dropped*.
- **(c) The sales channel's context carries no location, conversation id or customer id** (`AiReplyWorker:494-507`,
  `BrainContext.php:61-77`): the pin block never appears on sales and guard events record conversation 0.
  Consequence: `ai_lead_capture` and `ai_crm_lead_sync` read ON in Uganda's settings and have never done anything.
  **Fixing (a) and (b) switches on an external write (uCRM lead clients) that is configured but has never happened — the
  operator decides when; the fix ships behind the existing flags.**

## 4. Existing CRM / lead pipeline

One CRM, in two places: the plugin's `leads` table (SQLite, JSON rows; status vocabulary open · contacted · interested ·
quoted · qualified · won · lost, plus `dead`; `tabs/sales/leads.php:122-130`) linked from `wa_conversations.lead_id`, and
uCRM lead clients written by `UcrmLeadSync` (patch, link, refuse on ambiguity, or create `isLead` with a note;
`UcrmLeadSync.php:84-185, 299-350`). `LeadMatcher` matches a phone to an **open lead** by its last nine digits, skipping
won/lost/dead (`LeadMatcher.php:27-63`); client matching is `DishNetTools::identifyCustomerByPhone`, which answers
*ambiguous* rather than guess (`DishNetTools.php:47-139`). `QuotationService` marks a lead `quoted` (:193-197) — nothing
reads that downstream. **Do not create a second CRM**: intent and stage belong on these records.

## 5. Existing follow-up mechanism

Silence-triggered, WhatsApp-only, well guarded:
- Four `cron/master.php` jobs (`followup_close` → `followup_scan` → `followup_run` every 600 s, `followup_send` 300 s), all
  no-ops unless `followup_enabled` (:233-236). `followup_auto_send` approves enquiry-level SEND drafts without a person;
  account-level and escalated drafts always wait (`FollowUpPolicy::mayAutoSend` :96-139).
- One open follow-up per conversation (partial unique index, 070:44-45); one pending draft; **two attempts** at 24 h then
  72 h, hard-coded (`FollowUpService.php:23`, `FollowUpPolicy.php:57`); daily cap per channel; the 08:00–20:00 window;
  stop on reply, opt-out, escalation word; closed rows immutable by trigger (070:54-65); every send audited.
- The draft is written by `FollowUpEvaluator` (last 60 messages, attempt N of max, stored topic/product/objection; account
  facts only for a trusted identity) with verdicts SEND · DO_NOT_SEND · WAIT · ESCALATE_TO_HUMAN and stages enquired ·
  quoted · deciding · objection · ready · unknown (`FollowUpEvaluator.php:30,142-148,257`). Approval is the admin-only
  *Customer Follow-ups* tab (`tabs/engage/followups.php`, `api_followups.php` `fu_*`).
- **Gaps to fix before extending** (read from code, not seen in production): a closed follow-up can be re-opened by the
  next scan while the same silence continues (`followup_scan.php:49-52`; nothing requires `last_customer_at` to move);
  the daily cap is checked at draft time, not at send time, on a UTC day (`followup_send.php:59`; `FollowUpService.php:410-418`);
  `followup_send.php` has no per-draft claim, so a manual run beside `master.php` could send twice; `uncertain` drafts
  are invisible on the screen; `topic` and `context_summary` are never filled; the quotation sent by NotificationService
  lands in a support/accounts conversation and is invisible to a sales-channel follow-up (`ConversationService.php:232`,
  `NotificationService.php:2233-2234`).

## 6. Existing e-mail mechanism

- `lib/MailService.php`: synchronous SMTP (STARTTLS/SSL, AUTH LOGIN), settings from `email_settings.json` or uCRM's mailer;
  `send(to, toName, subject, html, text, extraHeaders, attachments, fromOverride)` (:336-339) — one To address, Cc works,
  **no Bcc**, HTML + text + attachments, **the subject is not sanitised of line breaks** (:623, 545-547). No queue, retry
  or rate limit. `JobNotifier` already e-mails staff through it (`JobNotifier.php:458-459`).
- Nine lifecycle templates (`CustomerEmails.php:33-42`) behind a master and per-event switch, with a claim/settle dedupe
  log (`CustomerEmailDispatcher.php:258-287, 414-511`); `tools/set_customer_emails.php --all-off` is the panic switch.
- Inbound-mail AI (`cron/inbound_mail.php`, every 300 s, JMAP read-only): filter → classify → match → draft → `email_drafts`
  (pending) → filed in the mailbox's Drafts folder; **a person sending from webmail is the approval**
  (`InboundMailWorker.php:89-191`, `DraftMailCopy.php`). It ignores anything carrying `X-DishNet-Auto`
  (`InboundMailFilter.php:120-123`) — **nothing sets that header today**, so an approval e-mail we send to a mailbox the
  AI reads must set it, or the AI will draft a reply to our own request.
- **No e-mail opt-out or marketing consent, no List-Unsubscribe, no bounce handling, no marketing/campaign/newsletter code.**
  The only bulk senders are dunning (`owb_bulk_send`, admin/accountant only), LTE reminders and the outage alert.

## 7. Existing scheduler, n8n and consent

- Scheduler: `cron/master.php` (system cron every minute + the uCRM heartbeat; one `flock`; 300 s budget) with intervals
  30 s–4 h, daily `run_hour`, and `run_dow` honoured only in the exact-hour branch (:337-342) — a missed hour skips the
  week. **A weekly job should run hourly and keep its own "last drafted" stamp**, as the backup job does (:244-248).
- n8n: `n8n/DishNet_Uganda_AI_Bot_v1.0.json` is **inactive** (README:3-4; JSON `"active": false`); 139 nodes; it needs the
  Hybrid's `webhook_secret` for customer lookup (empty on Uganda), expects media inline as base64 (off), labels voice
  notes `audio/wav` though WhatsApp sends OGG/Opus, validates nothing, and its own README warns that enabling it beside
  the plugin gives every customer two replies. **Reference only, for the per-type branch shape; never switch it on.**
- `dishnet-ai/`: a text-only older fork of the same stack, not in the production plugin listing (`docs/07`:4253-4255);
  byte-identical `ai_tools.php`; would compete for the same Evolution instances. **Do not extend; do not install.**
- Consent: WhatsApp opt-out by STOP words and staff action (`ContactOptOut`); the only consent table covers T&C/privacy for
  the customer portal (`api_customer_app.php:166`). E-mail has none.

## 8. Gaps (what the brief needs that does not exist)

1. Media retrieval, validation (size, MIME, timeout), transcription, image understanding, document extraction — none.
2. A unified inbound message model with `message_type`, media reference, extracted context and processing status — none
   (`wa_messages` has `media_type` and nothing else about media).
3. Per-turn intent, lead stage, next action, waiting-on — none; the follow-up stages exist only for silent conversations.
4. Event triggers for follow-up (quotation sent, document received, PO stage, payment stage, scheduled callback) — none.
5. E-mail follow-up drafts tied to a lead/conversation — the inbound-mail draft store exists but is keyed to inbound mail.
6. Weekly marketing draft, product rotation/history, approval states, an approval mailbox setting — none.
7. E-mail consent/unsubscribe, bounce handling, an approval-mail header (`X-DishNet-Auto`) on our own sends — none.
8. The lead path itself: defects (a), (b), (c) above.
9. Observability for the new steps (media downloaded, transcribed, processed; draft created; approval requested) — the
   `ai_platform.log` and `followup_events` exist; nothing reads `ai_security_events.json` or `ai_crm_actions.json`.
10. A customer who sends only a voice note, image or document gets **no answer at all** today, and staff no alert for ten
    minutes; wrapper message types are lost silently; the Inbox shows a type tag with no playback (`wa_inbox.php:382`).
11. An Evolution media client (`getBase64FromMediaMessage`) and a media record (mimetype, size, fetch status) — none.
12. STOP on a voice note is not detected; a transcript's figures would be "customer-said" amounts to the reply guard.

## 9. Proposed architecture (extend the live path; every piece behind a flag)

```
evo_webhook.php            unchanged shape; for audio/image/document it records a wa_media row {message_id (unique),
                           conversation_id, kind, mime, size?, status=pending} and queues ai.reply as today
ai.media event             NEW: a media-only or captioned-media message queues ai.media instead of ai.reply (flag on);
                           a SEPARATE worker class with its own lock (MediaWorker) does the slow part, so transcription
                           never holds the text replies: MediaFetcher (Evolution getBase64FromMediaMessage, size/MIME/
                           timeout limits, no disk retention)
                           → MediaUnderstanding: transcript (speech-to-text) | image description | document extraction
                           → the text is stored on the wa_messages row and joins the turn as "[Voice note, transcribed by AI]:
                             …" / "[Image, described by AI: …]" / "[Document, summarised by AI: …]" — the label is what keeps
                             the reply guard from treating a transcribed figure as a customer-stated amount
                           → then ai.reply as today: the EXISTING brain, guards, markers, hand-over
                           STOP detection runs on the transcript too; a media-only message gets a fixed acknowledgement if
                           the flag is off ("Thanks, I received it — our team will look at it") instead of today's silence
                           failures → a fixed holding line ("Thanks, I received it — our team will review it") + escalation
LEAD{json} marker          gains intent / stage / next_action / waiting_on; a fixed rule maps them to the leads row and the
                           followups columns (sales_stage, topic, context_summary) — the model proposes, the rule decides
Follow-up engine           new triggers (quotation sent, document received, callback asked) open or re-time the SAME
                           followups row; the re-open gap closed; cap checked at send; per-draft claim; kill switch =
                           followup_enabled off (exists) + followup_auto_send off (exists)
E-mail follow-up           drafts into email_drafts with lead/conversation keys; filed in Drafts; a person sends (exists)
Weekly marketing           cron job (hourly, own weekly stamp) → MarketingDraftService picks ONE product from the live uCRM
                           feed by rotation history → marketing_campaigns row DRAFT→PENDING_APPROVAL → ONE e-mail to the
                           configured approval mailbox (X-DishNet-Auto set) → never a customer send in this phase
```

Invariants: no second gateway, brain, CRM or follow-up engine; media bytes never written to disk or logs; the model never
decides a payment is received or a contract accepted; every inbound message/media/draft/e-mail has an idempotency key;
every new path is dark unless its flag is set; South Sudan is unchanged while its flags are unset.

## 10. Implementation plan — small verified batches

| Batch | Scope | Flag / switch | Tests |
|---|---|---|---|
| 0 | **BUILT (5.18.75), not deployed.** Lead-path defects (a) (b) (c) fixed; `tests/test_lead_path_batch0.php` drives a LEAD marker to a `leads` row with the real pin, a `crm.lead.sync` event and the uCRM worker reading `_payload`; four weakened copies caught | existing `ai_lead_capture`, `ai_crm_lead_sync` — **both ON in Uganda's production listing**; the operator decides before any deploy | worker-level, 25 assertions |
| 1 | **BUILT (5.18.76), not deployed, dark.** `wa_media` (migration 085) + `InboundMedia` normalisation + `MediaPolicy` + `MediaFetcher` (Evolution's `getBase64FromMediaMessage`, announced size refused before any call, allow-list per kind, strict decode, actual size, sha256, in memory only) + `MediaWorker` on its own lock and runner (`run_media_worker.php`, `ai_media` job) + the webhook's flag-gated branch; `tests/test_media_foundation.php` 99/0, six weakened copies caught — see `docs/07`, 5 Oct, "Batch 1" | `ai_media_enabled` (default off — **stays off**), `ai_media_max_bytes`, `ai_media_timeout_s` | fake Evolution media endpoint; OFF unchanged, ON recorded + fetched, duplicate delivery, caption still answered as text, unsupported kind, oversized (announced and actual), bad MIME, timeout, 500/404, malformed, retry, dead → `needs_human`, no disk retention, scrubbed logs, two locks |
| 2 | **BUILT (5.18.77), not deployed, dark; NO provider selected.** The boundary first (`docs/56`): `TranscriberPort` + `TranscriberFactory` (none → no provider; `fake` test-only) + the deterministic `FakeTranscriber`; `VoiceTranscription` on the Batch 1 worker path — audio only, both flags on: normalise, label `[voice message, transcribed]`, the transcript on the row and on the stored message, the existing STOP detection, ONE `ai.reply` event in the same transaction as `understood`; the existing AiReplyWorker → DishNetAiBrain (a VOICE MESSAGE block, `voice` = the fourteenth contract key) → ReplyPrivacyGuard (audit `modality: voice`); failures → `lib/Handover.php`, the worker's own hand-over moved there — never a guess. `tests/test_voice_media.php` 74/0, eight weakened copies caught — see `docs/07`, 5 Oct, "Batch 2" | `ai_media_voice` (default off — **stays off**; needs `ai_media_enabled`), `ai_media_voice_max_seconds`, `ai_media_voice_timeout_s`, `ai_transcription_provider` (`none` is the only value) | fake Evolution + fake transcriber + fake brain: valid note → transcript → one brain call, labelled; duplicate; non-audio ignored; too long / unreadable / too large → hand-over; timeout retried then succeeds; no provider / dead → hand-over with the holding line; STOP; guard block audited; nothing on disk or in logs; voice off; media off wins; CLI runner |
| 3 | Image → description → brain; payment screenshot = evidence, escalation, never a financial write | `ai_media_image` | fixtures (redacted), human-review rule |
| 4 | Document → extraction → brain; holding line when unreadable; no financial/contract action | `ai_media_document` | PDF/DOCX/XLSX/CSV fixtures, oversized, malformed |
| 5 | Intent + lead state on the LEAD marker; `followups` stage columns filled; escalation rules | `ai_lead_state` | marker → row mapping, model never lowers caution |
| 6 | Event-driven follow-up on the existing engine; re-open gap; cap at send; per-draft claim; visible `uncertain` | `followup_enabled` + `followup_auto_send` (exist) | lifecycle tests extended |
| 7 | E-mail follow-up drafts (Drafts-folder approval) | `email_followup_drafts` | draft store, no send path |
| 8 | Weekly marketing draft + rotation history + approval e-mail to `marketing_approval_email` only | `marketing_weekly_draft` (off) + the mailbox key empty = nothing sent | one draft per week, idempotent, format, no customer send |

Rollback for every batch: the flag off restores today's behaviour without a deploy; each batch is its own pinned release
with the deploy/rehearsal/rollback pattern used since 5.18.66.

## 11. Configuration, flags, observability — to be confirmed per batch

Keys (all default off/empty): `ai_media_enabled`, `ai_media_voice`, `ai_media_image`, `ai_media_document`,
`ai_media_max_bytes`, `ai_media_timeout_s` (the first and the last two exist since Batch 1, managed by `tools/set_config.php`;
the limits are refused outside their range rather than clamped silently), `ai_lead_state`, `email_followup_drafts`, `marketing_weekly_draft`,
`marketing_approval_email`. Secrets stay on the uCRM Configuration screen. Logging: one line per step in
`ai_platform.log` with ids and sizes only — never media, tokens, keys, full payment data.

## 12. Status against the brief's final report

| Capability | Status today |
|---|---|
| VOICE | NOT READY for customers — the path is built dark end to end (Batch 2, 5.18.77: recorded, fetched, transcribed through the provider boundary, labelled, answered by the existing assistant, handed over on failure), **but no transcription provider is selected or integrated** (`docs/56`), both flags are off everywhere, and nothing is deployed. Turning it on without a provider hands every voice note to a person. |
| IMAGE | NOT READY — recorded and fetched by the same foundation (JPEG/PNG/WebP); no description, no reply (Batch 3) |
| DOCUMENT | NOT READY — recorded and fetched by the same foundation (PDF, Word, Excel, CSV, text); no extraction, no reply (Batch 4) |
| WHATSAPP AUTO-FOLLOWUP | PARTIAL — engine exists (silence-triggered, two attempts, approval screen, auto-send flag); event triggers and the four gaps missing |
| EMAIL FOLLOW-UP | PARTIAL — draft store and Drafts-folder approval exist for inbound mail; no lead-tied drafting |
| WEEKLY MARKETING DRAFT | NOT READY — nothing exists |
| CUSTOMER MARKETING AUTO-SEND | MUST REMAIN DISABLED — and there is no code that could do it |
| APPROVAL EMAIL | bhavin@dishnetafrica.com — to be set in configuration, never hard-coded |

## 13. Sample weekly marketing draft — the FORMAT (prices and availability are never written by hand; the generator reads the live uCRM feed and the stock statement at the moment it drafts)

```
Subject:
Reliable internet for your business, installed by DishNet — Starlink in Uganda

Product:
Starlink (kit + monthly plan), from the live uCRM price feed at draft time

Target audience:
SMEs and shops in Kampala and upcountry with unreliable or no fixed-line internet

Customer problem:
Work stops when the connection drops; mobile data is expensive at business volumes; fibre is not available at the site.

Email draft:
[complete customer-facing e-mail, generated from the approved facts only: what Starlink is, that DishNet installs it,
 the plan names and prices exactly as the feed lists them, the tax sentence the quotations use, the payment facts from
 ai_fact_payment, the office fact, and the call to action — no promotion, no availability claim beyond stock_statement]

CTA:
WhatsApp the sales number / ask for a quotation / visit the website

Suggested campaign date:
[the next Tuesday after approval]

Why this product this week:
[the rotation history shows the last approved campaign and its audience; this is the next product/audience pair]

Approval:
PENDING BHAVIN APPROVAL
```

The e-mail carrying this draft goes to the configured approval mailbox only, with `X-DishNet-Auto: marketing-draft` so
the inbound-mail AI never drafts a reply to it. No customer receives anything in this phase; the campaign row stays at
`PENDING_APPROVAL` until a person changes it.

## 14. What this document does not do

It changes no code and no configuration. It does not decide which speech-to-text or vision provider to use (the brain
already has Claude and OpenAI credentials; the batch that needs one will measure cost and latency on fixtures first). It
does not approve any customer-facing automation: each batch is a separate release, dark by default, and the operator
switches each flag on, or not.
