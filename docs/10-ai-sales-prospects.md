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
