# 10 — The sales assistant and the prospect it could not remember

**Date:** 15 September 2026 · **Plugin:** 5.18.3 · **Status:** built, pending upload

## What happened

A prospect wrote to the sales number at 12:10. Over ten minutes he said hello
to a colleague by name, gave his name and his company, said he had just spoken
to us by phone, typed an email address for a quotation, and asked twice for a
basic quote for his business and his home. Every reply from the assistant was
a version of "how can I help you today?". The email address was answered with
"I'm here to assist with your internet service needs!". He wrote "Ok sir" and
waited.

## Why — two causes

**1. The model was never shown the conversation.** History replayed to the
model is bound to the identity resolved for the turn (B3.1), so a reassigned
or shared phone number can never inherit somebody's balance. A WhatsApp number
not in our billing system resolves to the identity `unknown`, and
`ConversationService::getMessagesForAi()` replayed nothing for `unknown`. The
sales number exists for people not yet in billing. Every one of them was
answered one message at a time, with no memory of the message before.

The refusal bought nothing. A turn written under `unknown` was produced with
no account data in the prompt — customer null, no services, no balance — so
replaying it to the same identity in the same epoch discloses nothing of
anybody's. The epoch is what protects a customer's turns, and it still does.

**2. The sales prompt did not say what a salesperson does** with a name, an
email address, a reference to a call, or a plain request for prices. "Qualify
before you recommend" read as "ask before you tell", and a typed email address
read as a message with no question in it.

## What changed

- **`unknown` replays its own recent turns.** `replayableIdentity()` is true
  for `unknown`; `getMessagesForAi()` adds a floor of
  `UNKNOWN_REPLAY_DAYS` (14) for that state, so a number the network later
  hands to a stranger does not carry the previous prospect's chat. Unchanged:
  a customer's turns never replay to an unknown caller (the key changed, so
  the epoch advanced); turns from a CRM outage never come back once the
  customer is identified again; an ambiguous number (several customers) still
  gets nothing; epoch 0 (pre-existing rows) is never replayed.
- **A prospect posture in the sales prompt** (`DishNetAiBrain::prospectRules`)
  when nobody in billing matched and the number is not ambiguous: read the
  conversation first and never open with "how can I help you today?"; a typed
  detail (name, company, email, town) is progress, not a question; a request
  for prices is answered first from PLANS, one qualifying question after the
  list, never instead of it; a colleague addressed by name or a reference to
  a call is acknowledged as the assistant covering the chat, never
  impersonated, and escalated so the colleague sees the thread; something to
  be sent to an email address is confirmed, recorded and escalated, never
  claimed as sent. An identified customer on the sales number stays in
  service mode; an operator override still removes only wording.
- **The lead carries the email.** `email` joins the LEAD keys and
  `AiLeadService::FIELDS`, validated (an address or nothing), lower-cased,
  shown on the All Leads screen, which already displayed the field.
- **One log line per turn, no content:** `conv N: identity=<state>
  history=<n> turn(s)`, so whether the model had the conversation in front of
  it is visible in `ai_platform.log` without reading anything a customer said.

## What was verified

| Check | Result |
| --- | --- |
| `tests/test_history_identity.php` (rewritten for the new rule) | 84 checks: a customer's turns never reach an unknown caller; the same unknown caller gets their own turns back in order; a turn older than the window is not replayed; an outage's turns replay during the outage and not after; ambiguous and epoch 0 unchanged |
| `tests/test_prospect_sales.php` (new) | 37 checks: the posture appears for unknown and web visitors, not for identified customers, ambiguous numbers, or the support number unless it sells; the email lands on the lead validated; the real worker's context builder, against a fake uCRM, hands the brain the four earlier turns on a prospect's third message, still serves an identified customer, and replays nothing once a number leaves billing |
| Prompt-pinning suites | `test_ai_brain` 153, `test_ai_lead_capture` 46, `test_ai_qualification` 54, `test_human_handover` 14, `test_brain_context` 122, `test_brain_customer_tools` 110, `test_shadow_runtime` 74 — all green |
| Prompt corpus | changed on purpose; new baseline `6cf66458a6816909feac0c43f91be4374d31cdc0913a9f86875bc6f59c2807ff` (was `f7a0f3157e8a3eeb111f45a2e3ece8ecb244fe0da1f876ab2e2b79d36075a13c`) |

## What was not changed

- No message text of any customer was read or quoted in this work; the fix
  was derived from the code path and the transcript the operator pasted.
- The 24-hour default for `wa_human_cooldown_minutes` (the AI stands down
  for a day after a colleague replies in WhatsApp) is unchanged; it is a
  setting, and 0 means the AI never stands down.
- Whether `ai_qualification` and `ai_lead_capture` are on for this install
  is read from the store at run time; the posture's LEAD wording appears
  only when lead capture is on.

## Rollback

Re-upload the 5.18.2 ZIP. The prospect posture disappears with it and
`unknown` callers stop receiving history again.

---

## Follow-up the same afternoon — 5.18.4: the sign-up moment, and what else the transcript showed

At 12:31 a prospect wrote to the sales number asking for Residential Lite,
then said Lira, four people. At 12:32 a colleague created the client in uCRM
by hand and sent a quotation. At 12:38 the prospect answered the assistant's
question ("browsing and streaming"); the assistant asked how many people
again and recommended "our DishNet Home plan". In between, the customer had
received a WhatsApp reading `EVENT CLIENT ADD / To: … / Customer name: … /
Crm id: 13`.

### Four causes, four changes

1. **The sign-up moment wiped the model's memory.** The identity went from
   `unknown` to a CRM client, so the epoch advanced, and by the rule above
   the prospect's turns stopped replaying — correctly for a reassigned
   number, wrongly for the same person one minute after being created in
   billing. Now a customer identified mid-conversation also sees the epoch
   immediately before, if its turns were written while the caller was
   `unknown` and inside the 14-day window. Only in that direction: a
   customer's turns never follow a number into `unknown`; nothing crosses
   two epochs (customer A → unknown → customer B: B sees the unknown turn,
   never A's); an ambiguous epoch is never carried; a stale unknown turn is
   not either.
2. **Service mode for someone in the middle of buying.** An identified
   customer on the sales number was told "existing customer — do not pitch".
   A client created five minutes ago with no service is a sign-up in
   progress. The worker now reads an identified customer's own services (one
   call, their own data) and passes `has_service`; with none live, the
   prompt carries an onboarding posture: carry the sale through, do not
   re-qualify, refer to the chosen plan by its exact name and price from
   PLANS, escalate when they say yes or ask to pay or about installation.
   `has_service` travels through `BrainContext` and `ShopBotPayload` only
   when established; absent means "not looked up" and keeps the old posture.
3. **"DishNet Home".** Knowledge-base row `PLAN_SERVICE_MAP` told the model
   to "present the DishNet plan name first" and mapped DishNet Home =
   Starlink Residential, DishNet Lite = Starlink Residential Lite. The uCRM
   plans were renamed; the row was not. The seed now says: use plan names
   exactly as they appear in PLANS; if a customer uses an old name, map it
   to the closest current plan and confirm the exact name and price. It
   reaches the live row through `tools/seed_knowledge.php --refresh-seeded`,
   which corrects rows still as seeded and reports, without touching, any
   row an operator edited. `tools/ai_eval_questions.json` updated likewise.
4. **"EVENT CLIENT ADD".** `NotificationService::send()` had no template
   behind it: it built one line per variable and sent that to the customer
   as the welcome for a client created by hand. It now sends exactly the
   text its caller wrote in `_raw_message`, or nothing at all, logging the
   omission. The `client.add` handler writes a real welcome with the support
   contact from config. The overdue WhatsApp follow-ups used the same method
   and were going out as the dump with their real text appended as "Raw
   message:"; they now go out as their text.

### Verified

| Check | Result |
| --- | --- |
| `tests/test_history_identity.php` | 94 checks: the carried turns, the direction, the two-epoch case, the ambiguous case, the window; the outage case re-stated (pre-outage turns never return; what the customer said during the outage follows them) |
| `tests/test_prospect_sales.php` | 55 checks: the onboarding posture with `has_service=false`, service mode with `true` or absent; the projection carries the leaf only when established; the real worker against the fake uCRM marks client 13 (no service) and client 14 (active) correctly and logs the count |
| `tests/test_client_add_welcome.php` | 13 checks: `send()` with no text sends nothing; with text sends exactly that text and none of the variable names; the handler and both overdue callers write their own text |
| Prompt corpus | unchanged from the 5.18.3 baseline (`6cf66458…`): the onboarding block appears only when `has_service` is present |
| Full suite | 168 suites, exit 0; seven break tests caught |

### On the server after upload

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/seed_knowledge.php --refresh-seeded
```

It prints which rows were corrected and which were protected because an
operator had edited them. `PLAN_SERVICE_MAP` should appear under corrected;
if it appears under protected, the row was edited in Admin → Knowledge Base
and the new wording has to be applied there by hand.

---

## 5.18.5 — a product named like a plan is the plan

Listing the catalogue exactly as the assistant receives it showed the uCRM
Products tab carrying two entries named like the monthly plans,
"Residential (up to 400 Mbps)" and "Residential Lite (up to 100 Mbps)". uCRM
builds quotations from Products, so the operator mirrored each plan there to
put it on a quote. To the prompt every product is a ONE-TIME charge, so the
assistant held each plan twice: once at its monthly price and once as a
one-off of the same amount — the exact way a model calls a monthly plan a
one-off, or adds it into the "total to get connected".

`DishNetTools::getProducts()` now drops any product whose name is a plan's
name before anything reads the list. Names are compared through
`catalogueKey()`: lower-case, the word "Starlink" removed, letters and digits
only, so "Starlink Residential Lite ( up to 100 Mbps)" and "Residential Lite
(up to 100 Mbps)" are recognised as one thing. The count of dropped mirrors
is returned and logged (`… 2 plan mirror(s) dropped from hardware`). The
mirrors stay in uCRM, where quotations need them.

Prices themselves were never the problem: every plan and kit price the
assistant states comes from uCRM on each turn, cached for one minute, and
the prompt forbids any price not in that list.

| Check | Result |
| --- | --- |
| `tests/test_prospect_sales.php` | 60 checks: the two spellings compare equal; through the real worker against a catalogue shaped like the live one, PLANS carries both plans as monthly and HARDWARE carries the kit and the installation only, with the drop logged |
