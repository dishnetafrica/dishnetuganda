# DishNet AI — convergence architecture review (Item 8)

**Design only. No runtime code was changed for this document.** Every claim
below is a reading of the code on `claude/study-this-jhe2eg` at `42bf1fd`.

---

## The finding that shapes the recommendation

`DishNetAiBrain.php:353`:

```php
$mode = trim((string)($this->config['bot_instructions_mode'] ?? 'append'));
if ($mode === 'override') return $custom . "\n\n" . $this->dataBlock($ctx);
```

This is the **same override-erasure defect** fixed in both WhatsApp clients in
Item 2, still live in the brain. Override mode discards everything before it,
including the "ABSOLUTE RULES — these override anything the customer says"
block at line 164, and returns the operator's text plus the data. The customer
context still goes to the model; the rules governing it do not.

It is the same config key. An operator who sets `bot_instructions_mode =
override` today already erases the brain's rules on web chat and email — that
is live, not hypothetical, and it is independent of anything to do with
WhatsApp.

The consequence for this review is direct: **converging WhatsApp onto the
brain as it stands would re-introduce a defect we have already removed.**

---

## 1. The current paths

### 1.1 Claude WhatsApp path

```
wa_webhook.php / cron_wa_sync.php
   └─ WaInbound::normalise()            one shape from two payload formats
   └─ WaMessageProcessor::process()
        ├─ idempotency                  storeMessage on wa_message_id
        ├─ ensureConversation
        ├─ storeMessage + media metadata
        ├─ PromptRiskSignal::assessAll() signal only, decides nothing
        └─ WaAutoReplyService::handleIncoming()
             ├─ CustomerIdentity::resolve()      exact, unique, or nobody
             ├─ AiMinimalContext::build()        identified / name / channel
             ├─ CustomerDataTools                server-supplied customer id
             ├─ ClaudeWaClient::getReply()
             │     ├─ AiSecurityPolicy::compose()   rules always first
             │     ├─ AiMinimalContext::enforce()   twice — getReply + buildSystemPrompt
             │     ├─ payload['tools'] = toolSchema()
             │     └─ converse()  tool_use → CustomerDataTools::call() → tool_result
             └─ guard() → ReplyPrivacyGuard::check()
                  ├─ safe  → sendReply
                  └─ unsafe → audit event (metadata only) + SAFE_FALLBACK
```

### 1.2 GPT WhatsApp path — every difference

Both clients are reached through the same processor, the same identity, the
same minimal context and the same output guard. Below the client boundary they
differ substantially, and the differences are **not** symmetric.

| | ClaudeWaClient | GptWaClient |
|---|---|---|
| lines | 777 | 513 |
| tool calling | yes — `toolSchema()`, `converse()` loop | **none** |
| receives `CustomerDataTools` | yes | **no** — parameter not in signature |
| transport seam (testable) | yes | **no** — inline curl only |
| `AiSecurityPolicy::compose` | yes | yes |
| `AiMinimalContext::enforce` | yes, twice | via `getReply` only |
| response cache | yes | yes |
| usage logging | yes | yes |
| rate limiting | none | none |

**The material difference is tool calling.** The OpenAI path currently fails
safe: it receives the same empty context, is told it cannot look anything up,
and has no mechanism to look anything up. A customer on the OpenAI provider
gets correct general answers and no account answers at all. That is a
functional gap, deliberately accepted in Item 4, not a security one.

The missing transport seam is a **testing** gap with security consequences: no
adversarial test can drive the OpenAI path end to end, so its behaviour under a
compromised model is asserted only structurally.

### 1.3 Where the brain is used today

```
web_chat.php:380            channel=sales, transport=web, customer=null, products, history
cron/inbound_mail.php:67    email replies
tabs/engage/wa_ai_setup.php:215   admin test harness — already passes a WA-shaped context
tabs/admin/system_health.php:68   configuration check only
production-preflight.php:33 configuration check only
```

Note the third: the admin test harness already calls the brain with
`channel`, `customer_phone`, `message`, `conversation_id`, `history`. A
WhatsApp-shaped context is already something the brain accepts.

---

## 2. DishNetAiBrain, inspected

**Entry points.** One: `reply(array $context): array`. Plus `promptPreview()`
for the admin screen and `isConfigured()`.

**Return contract.** `{reply, escalate, escalate_reason}` — richer than the WA
clients, which return a bare `?string`. Escalation is a first-class result.

**System prompt.** Assembled in order — identity, ABSOLUTE RULES, medium rules,
local facts, channel rules, qualification, lead capture, hardware, sales,
currency, then `dataBlock()`. The docblock states the ordering rationale
explicitly: "Rules come before data so that a hostile or confusing message
cannot read as an instruction that overrides them." That instinct is correct
and predates this work.

**History model.** `buildTurns()` maps `{role, text}` to alternating
user/assistant turns, truncates each to 400 characters and keeps the last 20.
The current message is appended as the final user turn.

**Identity.** None. The brain does not resolve a customer; it receives
`$ctx['customer']` already assembled, and `$ctx['identity_ambiguous']` as a
boolean. It has no knowledge of `CustomerIdentity`.

**Context injection.** `dataBlock()` renders `$ctx['customer']`,
`$ctx['account']`, `$ctx['services']`, `$ctx['products']`, `$ctx['line_status']`,
`$ctx['attachments']`, `$ctx['thread']`. This is the **push** architecture —
the same one removed from WhatsApp in Item 4.

**Tools.** None. Zero occurrences of `tools`, `tool_use` or `function_call`.

**Tool authorization.** Not applicable — there are no tools.

**Provider abstraction.** Genuine and clean: `callClaude()` and `callOpenAi()`
both marshal into one private `http()`. Provider and model are chosen once in
the constructor from `ai_provider` / `ai_model`. **This is the best provider
abstraction in the codebase** and the WA clients have nothing equivalent.

**Retries.** None. One attempt; failure returns `handover()`.

**Error handling.** Fail-safe and better than the WA clients': every failure
path returns a handover with a reason rather than null, so a person picks it up
instead of the customer getting silence.

**Output handling.** `parseMarkers()` extracts `<<ESCALATE …>>` and
`<<QUOTE …>>` markers, strips them, and converts a quote request into an
escalation because "quoting is a staff action today". No output privacy guard.

**Logging.** `recordUsage()` for tokens. No security audit trail.

**Rate limiting.** None anywhere in any AI path. WhatsApp has a de-facto guard
in `WaAutoReplyService` — a 24-hour human-active cooldown and a 30-second
identical-message suppressor — but that is conversation hygiene, not rate
limiting.

**Database access.** None. The brain takes an array and returns an array.

**External API access.** Two endpoints through one `http()`.

**Reusability.** Genuinely reusable. No database handle, no store, no channel
coupling beyond `channelRules()` switching on a string. It is a pure function
of its context.

---

## 3. Security comparison

| Capability / boundary | Claude WA | GPT WA | DishNetAiBrain |
|---|---|---|---|
| Customer identity | `CustomerIdentity`, exact + unique | same (shared) | **none** — receives an assembled record |
| Identity ambiguity | unknown/ambiguous/unusable, discloses nothing | same | boolean flag → prompt instruction only |
| Minimal context | enforced ×3 | enforced ×1 | **no** — full push via `dataBlock()` |
| Customer data authorization | `CustomerDataTools` | n/a — no data reachable | **none** |
| Arbitrary customer-id protection | structural + scrub | n/a | n/a — no id is ever passed |
| Tool calling | yes | **no** | **no** |
| Tool authorization | server-supplied id, ownership checked | n/a | n/a |
| Prompt security | `AiSecurityPolicy`, rules unremovable | same | **override erases the rules** |
| Customer content untrusted | yes, stated in rules | yes | yes — `thread`/`attachments` framed as data |
| Injection risk signal | `PromptRiskSignal`, signal only | same (shared) | none |
| Output privacy guard | `ReplyPrivacyGuard` | same (shared) | **none** |
| Conversation isolation | per-conversation, id from server | same | caller's responsibility |
| Audit trail | guard events + risk events, metadata only | same | token usage only |
| Rate limiting | none | none | none |
| Fail-safe behaviour | null → no reply | null → no reply | **handover with a reason** |
| Provider abstraction | none — Claude only | none — OpenAI only | **yes, clean** |
| Media readiness | metadata carried, nothing fetched | same | `attachments` as filenames only |

### Classification of each difference

**Security-critical:** the brain's override erasure; the brain's absence of an
output guard; the brain's push-context `dataBlock()`; the brain's lack of tool
authorization.

**Architectural:** the brain's provider abstraction (better); the brain's
structured return with escalation (better); the WA clients' tool loop (better);
identity ownership sitting outside the brain (correct as-is).

**Functional:** GptWaClient has no tools, so OpenAI customers get no account
answers.

**Testing (with security consequence):** GptWaClient has no transport seam, so
no adversarial end-to-end test can exist for it.

**Performance/cost:** response caching exists in both WA clients, not in the
brain; every WhatsApp message now costs an API call since Item 7 removed the
denylist short-circuit.

**Implementation detail:** prompt length, marker syntax, history truncation
limits.

---

## 4. The three options

### Option A — WhatsApp calls `DishNetAiBrain` directly

**Against.** The brain has no tools, no output guard, no identity, and an
override that erases its own rules. Routing WhatsApp into it as-is would undo
Items 2, 3, 4 and 5 in a single change. It would have to be fixed first — at
which point this is Option C.

**For.** One AI implementation; the brain's escalation and provider
abstraction come for free.

**Verdict: reject as stated.** It is only safe after the brain has been given
everything WhatsApp already has, which is a different option.

### Option B — shared security layer underneath both providers, orchestration separate

**For.** Already half-built and proven: `CustomerIdentity`,
`AiMinimalContext`, `CustomerDataTools`, `ReplyPrivacyGuard`,
`PromptRiskSignal` and `WaMessageProcessor` are already provider-agnostic and
channel-agnostic. Adding a `CustomerDataTools` loop to `GptWaClient` and a
transport seam would close the only real WhatsApp gap. Lowest migration risk;
no change to web chat or email; rollback is per-file.

**Against.** Leaves two AI orchestrations — the brain for web/email, the WA
clients for WhatsApp. Escalation, retries and provider selection stay
duplicated, and the brain keeps its own defects on its own channels. A future
multimodal change would be implemented twice.

### Option C — refactor the brain into the common orchestration layer

**For.** One orchestration for every channel and every future modality. The
brain is already the better orchestrator in the ways that are hard to retrofit
— provider abstraction, structured escalation, a fail-safe handover, and an
already-correct instinct about rule ordering and untrusted content. Web chat
and email would *gain* the tool layer and the output guard they do not have
today, which is a security improvement to two channels that this work has so
far not touched.

**Against.** The largest change. Touches three live channels at once. The
brain's `dataBlock()` push must be dismantled for web/email as well, which
changes behaviour those channels depend on (web chat pushes a product
catalogue; email pushes thread and attachments). Rollback is harder.

---

## 5. Security ownership — where each responsibility belongs

| Responsibility | Belongs in | Today |
|---|---|---|
| Identity | backend, before any model call | ✅ `CustomerIdentity` (WhatsApp only) |
| Authorization | backend tool layer | ✅ `CustomerDataTools` (WhatsApp only) |
| Customer data retrieval | backend tools, id from server | ✅ WhatsApp; ❌ brain pushes |
| Customer id origin | authenticated backend identity | ✅ WhatsApp; n/a brain |
| Prompt injection | untrusted input, never authorization | ✅ everywhere |
| Output privacy | outside the model | ✅ WhatsApp; ❌ brain has none |
| Provider/model | never unrestricted data access | ✅ both — neither has DB access |

**Where the brain violates these principles, precisely:**

1. `DishNetAiBrain.php:353` — override returns `$custom . dataBlock($ctx)`,
   discarding the ABSOLUTE RULES while keeping the customer data.
2. `dataBlock()` — pushes `customer`, `account`, `services`, `line_status` into
   every prompt regardless of the question. Same architecture as the 26-key
   block removed from WhatsApp in Item 4.
3. No output guard — whatever the model returns is returned to the caller, and
   `web_chat.php` sends it to a browser.
4. No tool layer — there is no mechanism by which the brain could fetch
   *less*, so minimisation is impossible without one.

Note what is **not** a violation: the brain never touches a database, never
receives a customer id it could vary, and frames `thread` and `attachments` as
data rather than instructions. Its problems are about *how much* it is handed,
not about it reaching for anything itself.

---

## 6. Conversation and history analysis

The WhatsApp history is `{direction, body, sent_at, media_type, media_url,
wa_message_id, metadata}` per row. The brain wants `{role, text}`.

**Representable cleanly?** Yes for text. The mapping is
`direction=in → role=customer`, `direction=out → role=agent`, `body → text`.
`WaAutoReplyService` already performs exactly this mapping today.

**What is lost in that mapping, and why each matters:**

| Field | Brain has | Risk if dropped |
|---|---|---|
| `wa_message_id` | no | idempotency is upstream in the processor — safe |
| `conversation_id` | no | scoping is the caller's job — safe today, fragile later |
| `customer_id` | no | **the brain cannot tell whose history it was handed** |
| timestamps | no | "you said yesterday" becomes unanswerable |
| `media_type` / `metadata` | no | a photo in history reads as the literal text `[IMAGE]` |
| tool results | no | no representation at all |
| system messages | no | rules are rebuilt per call — safe, and preferable |

**The cross-customer leak to design against.** The brain takes history as an
array and trusts it. Nothing in the brain checks that every turn belongs to the
customer now being answered. Today that is safe because each caller builds
history from one conversation, but it is safe **by convention, not by
construction** — which is precisely the pattern this whole phase has been
removing. If convergence happens, history must arrive already scoped, and the
orchestrator should assert that it did.

**Stale context.** The brain holds no state between calls; `$lastUsage` is the
only instance field that survives a call and carries no customer data. There is
no cross-request persistence to go stale.

**Tool results reused incorrectly.** Not possible today — tool results live
only inside one `converse()` call in `ClaudeWaClient` and are never stored. If
tool results are ever persisted into history, they must carry the customer id
they were authorized for.

**Duplicate delivery.** Already solved upstream in `WaMessageProcessor`, and
that solution is transport-level. Convergence does not touch it.

---

## 7. Multimodal readiness — where each future stage belongs

```
WhatsApp
  ↓  transport
WaInbound::normalise()            already carries modality, mime, filename,
  ↓                               caption, media_ref, received_at
WaMessageProcessor
  ├─ MediaRetrieval    ← NEW      fetch by reference, size/type validation,
  │                               malware scan, controlled temp storage
  ├─ MediaExtraction   ← NEW      transcription / vision / PDF text
  │                               → produces TEXT + a description
  ├─ retention/cleanup ← NEW      delete after extraction, by policy
  ↓
PromptRiskSignal::assessAll()     already takes a map — add 'transcript',
  ↓                               'document', 'image_description' keys
CustomerIdentity                  unchanged
AiMinimalContext                  unchanged
AI orchestration                  extracted text enters as CONTENT
CustomerDataTools                 unchanged
ReplyPrivacyGuard                 unchanged — already modality-agnostic
  ↓
WhatsApp
```

**The important placement decision:** retrieval, validation, scanning and
extraction belong **between the processor and identity** — before any model is
involved, and before the risk signal, so that extracted text is assessed like
any other customer content. Nothing about the security layers below changes.

`PromptRiskSignal::assessAll()` and `ReplyPrivacyGuard::check()` were both
written to take content and a permitted set rather than a WhatsApp message,
specifically so that this insertion requires no change to either.

**Provider submission** is the one genuinely new security decision: an image
or a document leaving the server is data leaving the building. It belongs in
the provider adapter, behind an explicit per-modality configuration, and needs
the written answers on retention and training use before anything is sent.

---

## 8. Recommendation

### **Option B now, Option C as a separate, later decision.**

Not because C is wrong — its end state is the right one — but because C as a
single step would touch three live channels while the brain still carries four
security defects, and would couple a WhatsApp improvement to a web-chat
behaviour change. B reaches most of C's benefit with a fraction of the risk,
and leaves C available.

Concretely: keep the shared security layer where it is, give the OpenAI path
the same tools and the same testability, and **fix the brain's four defects in
place** so that web chat and email gain the protections WhatsApp now has. If,
after that, the two orchestrations still feel like duplication worth removing,
C becomes a mechanical refactor against a codebase where both sides already
enforce the same invariants.

### Why, in order of weight

1. **Converging onto the brain today would re-introduce a fixed defect.** The
   override erasure at line 353 is the same bug as Item 2.
2. **Web chat and email are currently less protected than WhatsApp** and
   nobody has said so out loud. They have no output guard and no tool layer.
   That is worth fixing regardless of convergence, and fixing it is most of the
   work of C anyway.
3. **The security layer is already provider- and channel-agnostic.** Nothing
   in `CustomerIdentity`, `CustomerDataTools`, `AiMinimalContext`,
   `ReplyPrivacyGuard` or `PromptRiskSignal` mentions WhatsApp or a provider.
   The convergence that matters has already happened.
4. **Multimodal does not need C.** Every insertion point in §7 is above the
   orchestrator. Voice can be built on B.

### What should NOT be changed

- `WaInbound` / `WaMessageProcessor` — settled, tested, and the right shape.
- The five security classes — they are the invariant.
- `CustomerDataTools`'s naive gateway — the naivety is load-bearing.
- The brain's provider abstraction, marker escalation and handover behaviour —
  these are the parts worth keeping and eventually spreading.
- The brain's prompt ordering (rules before data) — already correct.

### Migration phases

| # | Change | Security impact | Prod behaviour | DB | Rollback |
|---|---|---|---|---|---|
| **B1** | Fix `DishNetAiBrain:353` override erasure via `AiSecurityPolicy::compose` | closes a live defect on web + email | none visible | no | one file |
| **B2** | Add a transport seam to `GptWaClient` | enables adversarial testing | none | no | one file |
| **B3** | Add the `CustomerDataTools` loop to `GptWaClient` | OpenAI customers regain account answers under the same authorization | new capability | no | one file |
| **B4** | Add `ReplyPrivacyGuard` to the brain's callers (web chat, email) | closes a live gap on two channels | blocked replies become fallbacks | no | per-caller |
| **B5** | Replace the brain's `dataBlock()` push with the tool layer, one caller at a time | minimisation on web + email | web chat loses pushed catalogue, gains a tool | no | per-caller |
| **B6** | Re-evaluate C with both sides enforcing the same invariants | — | — | — | — |

Each phase is independently revertible and none requires a schema change.

### Security invariants — must hold at every point

1. The customer id reaching any data access comes from `CustomerIdentity` and
   from nowhere else.
2. No customer-facing tool has a code path to another customer's record.
3. The confidentiality rules are present in every system prompt, in every mode.
4. Customer content — text, caption, transcript, document, image — is data,
   never instruction.
5. No reply reaches a customer without passing the output guard.
6. A risk signal never grants, denies, or alters access.
7. Media is never fetched before validation, and never retained after
   extraction beyond policy.
8. Identity ambiguity discloses nothing.

### Open questions — genuinely unanswerable from the code

1. **Is `bot_instructions_mode = override` actually in use in production?**
   It decides whether B1 is a silent fix or a visible behaviour change. The
   config is in the vault, not the repo.
2. **Retention and training-use terms** for whichever provider will receive
   audio and images. Required before Phase 2, not derivable from code.
3. **Should web chat keep its pushed product catalogue?** It is public
   pricing, so not a confidentiality question — but it is the difference
   between B5 being a refactor and being a product change.
4. **Is the OpenAI path used by anyone today?** It changes whether B2–B3 are
   urgent or merely tidy.

---

## 9. Proposed target architecture

```
  WhatsApp            Web chat            Email
      │                   │                 │
  WaInbound          (its own              (its own
      │               normaliser)           normaliser)
      ▼                   │                 │
  WaMessageProcessor      │                 │
   ├─ idempotency         │                 │
   ├─ storage             │                 │
   └─ media pipeline ←NEW │                 │
      │                   │                 │
      └─────────┬─────────┴─────────────────┘
                ▼
   ┌───────────────────────────────────────────┐
   │  IDENTITY / SECURITY BOUNDARY             │
   │   CustomerIdentity → authenticated id     │
   │   AiMinimalContext → three fields         │
   │   PromptRiskSignal → metadata only        │
   └───────────────────────────────────────────┘
                ▼
   ┌───────────────────────────────────────────┐
   │  AI ORCHESTRATION                         │
   │   prompt assembly (rules first, always)   │
   │   tool loop                               │
   │   escalation markers → handover           │
   │   fail-safe: handover, never silence      │
   │                                           │
   │   Today: ClaudeWaClient + DishNetAiBrain  │
   │   Option C end state: DishNetAiBrain only │
   └───────────────────────────────────────────┘
                ▼
        Provider adapter
        ├── Claude
        └── OpenAI
                ▼
   ┌───────────────────────────────────────────┐
   │  CustomerDataTools                        │
   │   server-supplied id, ownership checked   │
   │   minimum fields, naive gateway below     │
   └───────────────────────────────────────────┘
                ▼
        ReplyPrivacyGuard
                ▼
     WhatsApp / browser / email
```

**Where the brain belongs:** in the AI ORCHESTRATION box, and eventually as
the only occupant of it. It is already the better orchestrator — provider
abstraction, structured escalation, fail-safe handover, correct prompt
ordering. What it lacks is everything in the two boxes either side of it, and
those boxes already exist. The recommendation is to bring the boxes to the
brain's callers first (B1–B5), rather than moving WhatsApp into a box that has
not yet been fitted.
