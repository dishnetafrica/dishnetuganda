# 24 — The pin nobody received

**Date:** 18 September 2026 · **Plugin:** 5.18.18 · **Status:** built, pending upload
**Phase:** 1 of 3 (location). Phases 2 (uCRM lead) and 3 (quotation) are not started.

## What was happening

The sales assistant asks "which area?" — it is in the prompt, and it is the
right question. A customer answering it with a WhatsApp location pin, which is
the most useful answer available, got **nothing back at all.**

`evoExtractText()` in `evo_webhook.php` knew eight message shapes: a plain
conversation, an extendedText, three media captions and three button replies.
`locationMessage` was not among them, so a pin produced an empty string, and
four lines later:

```php
// Media-only messages are stored and surfaced to staff but not sent to the
// AI, which cannot act on them yet.
if ($text === '') { $skipped++; continue; }
```

The AI was never queued. The customer waited. Meanwhile
`ConversationService::importEvoMessage` stored the message as the literal body
`[LOCATION]` — the fact that a pin arrived, with the latitude and longitude it
carried thrown away unread.

Two losses, and the second is the quiet one:

- **the customer got silence**, which reads as being ignored;
- **the coordinates were discarded at the door** — the one fact an
  installation cannot proceed without, present in the payload, never read.

Nothing recorded it as a failure. The counter went up by one and the
conversation looked, afterwards, like somebody who stopped replying. The
watchdog would page a human after ten minutes or so, as a generic unanswered
message — which is why this was survivable, and why it stayed invisible.

## What 5.18.18 does

**`lib/WaLocation.php`** is the one place that understands a pin, used by both
inbound paths so they cannot disagree. A pin has **three** outcomes, not two:

| | |
|---|---|
| **Not a location** | node absent, numbers missing or not numbers, off the globe, or exactly `0,0` — Null Island, which is what a missing coordinate becomes after `(float)null`. Treated as though no pin was sent, and **logged**. |
| **Out of area** | real coordinates outside the deployment country's box. Kept, stored, flagged. The assistant says so and asks them to confirm. |
| **In area** | used. |

The middle row is the one worth arguing about. Discarding an out-of-area pin
would reproduce the original failure with a better excuse: our box may be too
tight, or the site may genuinely be across a border, and either way a person
should see the pin and the doubt together.

The country comes from the `timezone` already set per deployment
(Africa/Kampala → Uganda, Africa/Juba → South Sudan), so it is not a new
setting to forget. `geo_country` overrides it where that is wrong.

**The pin now reaches the AI.** `WaLocation::mergeText()` puts a description
where the empty string used to be, so the message is queued and answered. A
caption plus a pin keeps both halves. The function exists rather than living
inline in the webhook precisely so it can be tested — the decision that lost
the customer was previously untestable.

**The coordinates travel as data, not prose.** They ride on the event
separately from the text. The model reads a description; what gets written
down comes from the record.

**Storage.** Migration 072 adds `location_lat` / `location_lng` to
`wa_messages`, and the inbox body becomes `[LOCATION] 0.3354, 32.5876` instead
of `[LOCATION]` — a colleague opening the conversation can now see where.

**The lead.** `AiLeadService::capture()` takes a fourth kind of input: facts
the *system* established, merged after the model's have been filtered. The
worker looks up the conversation's most recent pin and passes that. A
coordinate the **model** supplies is impossible to write — `location_lat` is
deliberately not in `FIELDS`, so `clean()` drops it before it can reach
anything. A model asked for a latitude will produce one; it will be the middle
of a country it has heard of, and an installer would drive to it.

The lookup is by conversation, not by turn: the pin and the sentence that
qualifies the lead are almost never the same message. A customer sends the
pin, and two replies later says "yes, quote me".

**A pin counts as a location** for the qualification floor. It is a better one
than a typed place name, being the only kind an installer can navigate to.

**The assistant is told what it cannot do.** It has no map. It must not name
the town, estimate a distance or travel time, or say whether we cover the
site — a colleague confirms coverage. A confident guess about where somebody
lives would be believed.

## Storage must never depend on this

`storeMessage()` runs for **every** message in and out. The first version named
the new columns unconditionally, and `test_conversation_order.php` — which
builds its schema by hand — began failing with `no column named location_lat`.

That test failure was worth more than it looked. `MigrationRunner` is
fail-fast: a migration that fails stops the run and skips everything after it.
So on any install where 072 had not run, or had failed, **every WhatsApp
message would have thrown a PDOException** and the inbox would have stopped.
A pin is worth having; it is not worth that.

The insert is now built from the columns the database actually has, checked
once per process. Where they are absent the coordinates are dropped, the
message is stored exactly as before, and a line goes to the log saying why.

## Tests

`tests/test_location_pin.php` — 79 assertions across twelve sections: a valid
pin; invalid coordinates (0,0, both poles, off the globe, words); missing
coordinates; bounds in both countries and the country derived from the
timezone; what the assistant is shown; a pin rescuing a message that would
otherwise be dropped; storage through the real migration; typed locations,
ordinary text and media-only messages all unchanged; the webhook wiring and
its call site; the prompt block and its fences; the lead accepting a system
pin and refusing a model's; the cross-turn lookup; and a pre-migration
database storing every message without throwing.

Nine mutations each fail it: 0,0 accepted; bounds always true; coordinates not
stored; the model's coordinates allowed through; a pin no longer rescuing an
empty message; a caption discarding the pin; the webhook dropping the call; the
no-map fence removed; and the column check removed.

Two of those nine escaped the first version of the test and are the reason the
suite is shaped as it is — a source-grep that checked *ordering* did not notice
that the rescue had been deleted, and nothing noticed the webhook no longer
calling the function at all.

Full suite: 177 suites, 6555 assertions, 0 failed. Prompt corpus unchanged
(`ba05b3dd`) — the pin block appears only when a pin does.

## What this does NOT do

- No uCRM lead or customer is created. That is Phase 2.
- No quotation. That is Phase 3.
- No geocoding, no reverse lookup, no place names. The system knows
  coordinates and nothing else about them, and says so.
- A photo with no caption is still stored and still not answered — unchanged,
  but now logged rather than counted silently.
