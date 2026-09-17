# 23 — The marker that reached a customer

**Date:** 17 September 2026 · **Plugin:** 5.18.17 · **Status:** built, pending upload

## What happened

09:35. A prospect on the sales number gave their village, then their name.
The assistant answered well: it acknowledged both, named the plan and the
total, and said the team would confirm the next steps. Then, on the end of
the same WhatsApp message, the customer read this:

```
<<LEAD {"requirement":"...","location":"...","customer_name":"...", ... }>
```

Their own name and location, as JSON, quoted back at them, with the internal
field names attached.

## Why

`<<LEAD {json}>>` is how the model hands a sales opportunity to the CRM. It is
machine syntax and it is supposed to be removed before anything is sent. The
model closed it with **one** angle bracket instead of two.

Every pattern that handles it required two:

```php
preg_match('/<<\s*LEAD\s*(\{.*?\})\s*>>/is', $raw, $m)   // no match
preg_replace('/<<[^>]*>>/', '', $raw)                      // no match either
```

So nothing matched, nothing was stripped, and the marker travelled with the
reply. Two failures at once, from one missing character:

- **the lead was never recorded.** The conversation had reached a real
  opportunity — location, name, plan, hardware, quote requested — and none of
  it reached the CRM.
- **the customer was shown the machinery.** This is the worse one. Someone who
  has just handed over their name and where they live gets it read back as a
  data structure. It looks like a system talking about them rather than to
  them, and it is the kind of thing that ends a sale on its own.

The file's own comment said stripping was unconditional — *"we remove any
<<...>> block whether or not we recognise it"*. That was true of the intent
and false of the code: every path through it assumed the model would close the
marker exactly as instructed.

## What 5.18.17 changes

**The JSON is walked, not matched.** `takeLeadMarker()` finds `<<LEAD`, then
counts braces from the first `{`, respecting strings and escapes, to find the
real end. A `}` or a `>` inside a value can no longer end it early. Then it
consumes however many `>` the model chose to close with — two, one, three, or
none at all.

**Unterminated JSON is cut, not shown.** If the braces never balance there is
no lead to save and no way to know where the marker ends, so everything from
`<<LEAD` onward is removed. The sentence before it is kept.

**Every other marker tolerates one bracket too** — `ESCALATE`, `QUOTE`,
`FLYER`, `PHOTO`, `DOC`. A malformed `<<ESCALATE customer is angry>` now still
hands the conversation to a person instead of printing the words to them.

**And a net underneath all of it.** After the specific strips, any run that
opens with `<<` and names a marker we know is removed to its closing bracket,
or to the end of the message if it has none. It matches our own six names
only, so a customer's text that happens to contain `<<` is not ours to
rewrite.

The order matters: the brace walk runs first, because the net stops at the
first `>` and a `>` can legitimately sit inside the JSON.

## Tests

`tests/test_ai_lead_capture.php` gains 25 assertions. Four closers (`>>`, `>`,
none, `>>>`) each: nothing of the marker reaches the customer, and the lead is
still recorded. A brace inside a value, an angle bracket inside a value and a
nested object: stripped whole and still decoded. Unterminated JSON: cut, with
the sentence before it intact. All five other markers with one bracket:
invisible to the customer, and their intent still acted on. And text that
merely contains `<<`: left alone.

Four mutations fail it — the old two-bracket rule restored, the net removed,
the brace walk replaced by a stop at the first `}`, and the net widened to any
`<<` at all.

## What this does not fix

The reply itself was good, and one thing in it was not the plugin's to fix:
the message went out headed **Secure-Africa Solutions Limited**, because the
WhatsApp Business greeting on the sales number still carries another company's
name. A customer who reads that, then a bank account in a third name, is being
shown the shape of a scam however honest all of it is.
