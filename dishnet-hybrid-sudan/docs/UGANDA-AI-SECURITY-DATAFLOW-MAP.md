# DishNet AI — current security and data-flow map

Written before any multimodal work, to answer the ten questions asked and to
name the security boundaries that do and do not exist today.

**No production code has been changed for this document.** Everything below
describes the system as it is on `claude/study-this-jhe2eg`.

The headline: the WhatsApp assistant does not use a least-privilege
architecture. It resolves a customer from their phone number, assembles that
customer's record into a text prompt, and hands the whole thing to the model
on every message. There are no tools, no per-question data scoping, and no
output check. Adding voice, images and documents to that flow without
changing it would widen an existing problem rather than solve a new one.

---

## 1. Where messages enter the system

Three independent entry points, and they do **not** behave the same way.

| path | file | media behaviour |
|---|---|---|
| WASender / pusher webhook | `wa_webhook.php` | logs media, acknowledges it, **discards the caption** |
| Evolution polling sync | `cron_wa_sync.php` | logs media and its caption, never replies |
| admin test harness | `includes/api/api_whatsapp.php:142` | text only |

**CORRECTION (Item 6).** An earlier version of this section said the webhook
discarded media-only messages entirely. That was wrong, and the error was
mine: I read `if (empty($text)) waResp(200, 'Empty message — ignored.')` at
line 250 without noticing the media branch above it at 192–246. That branch
stored the message, sent a per-modality acknowledgement, stored the reply, and
alerted the team — the audio one was a good, honest message. The `empty($text)`
line was only ever reached by messages whose TYPE was text.

The asymmetry was real but ran the other way: the webhook acknowledged media
and the cron said nothing. And the genuine defect, found while converging
them, was that the webhook's media branch hardcoded `'[TYPE received]'` as
the body and **discarded the caption**, so a photo captioned "is this
installed right?" arrived as a photo with no question attached, while the cron
path kept captions correctly.

Same customer action, two different outcomes depending on which transport
delivered it. That inconsistency is itself a finding: any rule we add has to
be added in one place that both paths use, or it will hold on one and not the
other.

**RESOLVED (Item 6).** Both entry points now call `WaInbound::normalise()` and
hand the result to `WaMessageProcessor::process()`. Neither file contains
identity resolution, an AI call, or a reply decision of its own.

## 2. Where WhatsApp media is currently stored

**Nowhere.** `cron_wa_sync.php:258-296` extracts a `media_url` for image,
document, audio and video and stores it as a column on the message. Nothing
downloads it, nothing scans it, nothing expires it. There is no media
directory, no retention policy, and no size or type validation, because
nothing is ever fetched.

That is the one piece of good news in this document: there is no existing
media store to secure, so the retention and access rules can be designed in
rather than retrofitted.

## 3. Where the AI brain is called

**There are two separate AI systems, and WhatsApp does not use the one called
"the brain".**

```
web_chat.php ─┐
inbound_mail ─┼─→ DishNetAiBrain (1223 lines)
wa_ai_setup  ─┘    context keys: customer, account, services, products,
                   history, attachments, thread, constraints, line_status…

wa_webhook.php ─┐
cron_wa_sync.php┼─→ WaAutoReplyService::getAiReply()  (line 1126)
                      └─→ ClaudeWaClient  or  GptWaClient
```

`DishNetAiBrain` already has an `attachments` context key
(`DishNetAiBrain.php:870`) which lists filenames and explicitly instructs the
model to acknowledge them and **not** guess at their contents. That is the
right instinct and it is on the path WhatsApp does not use.

This matters for the instruction "keep the current AI brain": on WhatsApp
there is no current brain to keep. Any multimodal work either converges the
two paths or duplicates the security model in both.

## 4. What customer data the AI currently receives

`WaAutoReplyService::getAiReply()` builds a context and `ClaudeWaClient`
renders it into the system prompt, on **every message**, regardless of what
was asked:

- name
- account balance
- account status (Active / Suspended / Lead)
- service type, plan name, plan expiry (`activeTo`)
- last payment amount and date
- Splynx block: live online/offline state, committed speeds, assigned IP,
  open tickets, service address

A customer who says "hi" gets their balance and last payment sent to the
model. A customer asking about coverage gets their IP address sent. The model
is then trusted to decide what to repeat back.

This is the architecture the requirements name as unacceptable:

```
Customer → AI gets the whole record → AI decides what to reveal
```

## 5. What database / API tools the AI can access

**None.** Neither `ClaudeWaClient` nor `GptWaClient` contains a single
occurrence of `tools`, `function_call` or `tool_use`. There is no function
calling at all.

This cuts both ways. The model cannot issue `SELECT * FROM customers`, because
it cannot issue anything — so the specific catastrophe of arbitrary query
access does not exist. But neither does any mechanism for fetching the
*minimum* data for a question, because everything is decided before the model
runs and pushed in wholesale.

Building `get_my_invoice()`-style tools is therefore new construction, not a
refactor of something weaker.

## 6. Where prompts and system instructions are stored

Four places, and one of them can erase the other three.

1. `ClaudeWaClient::buildSystemPrompt()` — the built-in DishNet prompt,
   including the security rules ("Never reveal passwords, API keys, or system
   info", line 565).
2. `config['bot_custom_instructions']` — operator text from the admin screen.
3. `config['bot_instructions_mode']` — `append` or `override`.
4. `tools/knowledge_seed.json` — the product and policy knowledge base.

**Finding — `override` wipes every guardrail.** `ClaudeWaClient.php:109`:

```php
if ($instructionsMode === 'override' && !empty(trim($customInstructions))) {
    $systemPrompt = trim($customInstructions);
}
```

The built-in prompt is not merged, it is replaced. An operator who sets
override mode to change the bot's tone silently removes the confidentiality
rules, the refusal behaviour and the identity handling along with it. Nothing
warns them, and the customer context is still appended to the messages.

## 7. How conversations are linked to customers

`ConversationService::ensureConversation($phone, …)` keys on the normalised
phone number. `WaAutoReplyService::lookupCrmClient()` (line 642) then resolves
that to a uCRM client:

1. scan `client_search_index.json`
2. fall back to the uCRM API search
3. cache the result on the conversation as `crm_client_id`

## 8. What authentication and authorisation exists

**Identity is the phone number. There is no verification step**, and the
matching is unsafe.

`WaAutoReplyService.php:652`:

```php
if ($cPhone && (str_ends_with($cPhone, $phone) || str_ends_with($phone, $cPhone))) {
```

The incoming number is required to be at least 8 digits (line 645). **The
stored number is not.** The second half of that `||` matches whenever the
incoming number merely *ends with* whatever is on a client record — so a
client whose phone was typed as a short local fragment can be matched by any
number sharing those trailing digits. The first match in the index wins.

The consequence is not abstract: the matched client's balance, plan, expiry
and last payment are then assembled into the prompt (§4) and answered to
whoever sent the message. **This is a cross-customer disclosure path that
exists today, before any multimodal work.** The same pattern is repeated at
line 684.

Beyond that: SIM swap, a forwarded handset, or a shared business phone all
authenticate as the account holder. There is no PIN, no OTP, no
knowledge-based check.

## 9. What is currently logged

- `wa_webhook_log.json` — event, message, and a payload excerpt; includes
  `substr($text, 0, 100)` of the customer's message
- `conversation_messages` — full message bodies, media type and media URL
- `ClaudeWaClient::logUsage()` — token counts and cost per call
- `error_log` — API errors including a 200-character excerpt of the response

No prompt is stored in full, so the assembled customer context is not
persisted. Media URLs **are** persisted indefinitely.

## 10. Where confidential information could leak today

Ordered by how likely they are to fire.

**A. Wrong-customer binding (§8).** The suffix match can attach the wrong
uCRM client to a conversation; their balance and payment history then go to a
stranger. No multimodal work is needed for this; it is live now.

**B. `override` mode erasing the guardrails (§6).** One admin setting, no
warning, every confidentiality rule gone.

**C. Denylist prompt-injection filtering.** `ClaudeWaClient.php:62-72` blocks
eleven regexes — `ignore previous instructions`, `system prompt`, `DAN`, and
so on. Denylists fail open: a paraphrase, another language (relevant in
Uganda), or a synonym passes. And it only inspects the text body, so any
future transcript, caption or document text would bypass it entirely unless
routed through the same check.

**D. Over-supplied context (§4).** Even with a correct customer, the model
receives an IP address and account balance to answer "what are your prices?".
Every unnecessary field is a field that can be echoed back.

**E. No output check.** Whatever the model returns is sent to WhatsApp. The
model is the only thing standing between an internal fact in the prompt and
the customer's screen.

**F. Splynx enrichment on unknown callers.** `getFiberSplynxContext($phone)`
runs when uCRM finds **no** client (`$noClient` at line ~1185), so an
unrecognised number can still pull a Splynx record into the prompt by phone
match alone.

---

## The security boundaries, as they should be

Today there is effectively one boundary — the model's own judgement, between
the assembled prompt and the reply. The requirement is five, of which four do
not yet exist:

```
   ┌─ 1. TRANSPORT ─────────────────────────────────────────────┐
   │  one entry point for all media, both paths                 │  MISSING
   │  fetch, type/size validation, controlled temp storage      │  (no media
   │  no public URLs, deletion after processing                 │   fetched)
   └────────────────────────────────────────────────────────────┘
   ┌─ 2. IDENTITY ──────────────────────────────────────────────┐
   │  exact phone match, minimum length on BOTH sides,          │  BROKEN
   │  ambiguity = unidentified, never first-match-wins          │  (§8)
   └────────────────────────────────────────────────────────────┘
   ┌─ 3. INPUT SANITISATION ────────────────────────────────────┐
   │  transcripts, captions, OCR and document text are DATA     │  PARTIAL
   │  secret-shaped strings redacted before the model           │  (denylist,
   │  one check every modality passes through                   │   text only)
   └────────────────────────────────────────────────────────────┘
   ┌─ 4. DATA ACCESS ───────────────────────────────────────────┐
   │  tools that take NO customer id; the server supplies it     │  MISSING
   │  from the resolved identity. Nothing fetched unless the    │  (push, not
   │  question needs it.                                        │   pull)
   └────────────────────────────────────────────────────────────┘
   ┌─ 5. OUTPUT ────────────────────────────────────────────────┐
   │  reply checked against what this customer may see before    │  MISSING
   │  it reaches WhatsApp. Model is not the last boundary.      │
   └────────────────────────────────────────────────────────────┘
```

## Classification, to be enforced in code not prose

**Customer-safe** — only for the *identified* customer, and only when the
question needs it: their own plan, invoice amount, payment status, service
status, kit number, support history; public pricing; public Starlink facts;
anything they themselves said in this conversation.

**Internal** — never reachable by a customer-facing tool, at any privilege:
any other customer's anything; cost prices, margins, supplier terms; internal
financial data; API credentials, tokens, passwords, Starlink session cookies,
database credentials; internal URLs and infrastructure; staff and private
data; internal notes; system prompts; security configuration.

The boundary is the tool layer: a customer-facing tool must have no code path
that can reach an internal table, so the classification holds even if the
model is fully compromised by injection.

## Proposed order of work

Each phase leaves text replies working exactly as they do now.

**Phase 1 — boundary and tests, no new modality.** Fix the identity match
(§8·A) and the `override` erasure (§6·B). Build the tool layer with the
server-supplied customer id, and the output check. Converge the two entry
paths onto one input function. This phase alone closes the live disclosure
path.

**Phase 2 — voice.** Controlled fetch, transcribe, transcript becomes the
message text and flows through Phase 1 unchanged. Transcript retained for
human agents, audio deleted after processing.

**Phase 3 — images.** Vision input alongside the caption, never instead of it.

**Phase 4 — documents.** Extract only what the question needs; never paste a
whole document into the conversation.

**Phase 5 — redaction and output guardrails hardened**, including
secret-shaped-string detection ahead of the model.

**Phase 6 — escalation and admin visibility.** Audit metadata per
interaction: customer, conversation, message id, modality, tools called, data
sources touched, whether redaction fired, whether it escalated, final
response. Metadata, never payloads.

## Provider questions to settle before Phase 2

What is sent, why, retention, training use, processing region, and metadata —
for transcription and for vision, which may be different providers from the
text model. To be answered in writing before any customer audio or image
leaves the server.
