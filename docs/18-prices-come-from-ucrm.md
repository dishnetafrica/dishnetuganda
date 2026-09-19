# 18 — Four prices that did not exist: the support number and the missing guard

**Date:** 16 September 2026 · **Plugin:** 5.18.10 · **Status:** applied 16 September 2026 (manifest verified; baseline at install 0 blocks, 0 support turns with the catalogue — none had arrived yet)

## What happened

At 08:39 on 16 September a prospect wrote to the support number and asked
about Starlink packages, then the initial cost, then a monthly plan, then
whether the data was truly unlimited. The assistant answered every question
fluently and gave a kit price, an installation price, a total to get
connected and two monthly plan prices. None of the four figures exists in
uCRM. The Residential Lite price it gave was less than a third of the real
one; the kit price was less than two thirds; the installation price was
higher than the real one.

## Why

The log for that conversation shows six support-channel turns and no
`catalogue loaded` line after any of them. Every sales-channel turn that
morning has one. Three faults lined up:

1. **The catalogue was fetched for the sales number only.** The worker's
   context builder called the uCRM plan and product lookup under the sales
   case alone. Support and account, which share one WhatsApp number on this
   installation, never received PLANS or HARDWARE.
2. **Nothing told the model the list was missing.** The data-section line
   "PLANS: unavailable right now. Do not name any plan or price" was
   attached only when the channel was sales. On support there was no fence.
   And `ai_sales_on_all_numbers`, which is on here, adds a block telling
   every number to answer what-it-costs questions "from PLANS". The support
   number was instructed to quote from a list it did not have, and filled
   the get-connected template from memory. "Say you will confirm" is a
   prompt instruction, not a boundary.
3. **The guard was not on this path.** `ReplyPrivacyGuard` has carried the
   rule this needed since B3: money the tools did not return and the prompt
   does not contain is refused, and the whole reply goes. It was wired into
   the WASender webhook, the path South Sudan used, and never into
   `AiReplyWorker`, the path Uganda runs. Zero calls to it existed in the
   worker or the brain.

## What 5.18.10 changes

**Every number that sells gets the price list.** The catalogue lookup is
one method, `loadCatalogue()`, called on the sales number as before and on
support and account whenever `ai_sales_on_all_numbers` is on. The
`catalogue loaded` log line now follows support-channel turns too.

**The fence is on every channel.** When the catalogue is absent, the data
section says on every channel: "PLANS: unavailable right now. Do not name
any plan or price from memory — a price you were not given does not exist.
Asked what we offer or what it costs, take their requirements and hand
over. Amounts shown under THEIR SERVICES or ACCOUNT are the customer's own
and may be stated." The last sentence keeps the accounts number able to
state a customer's own balance. The HARDWARE fence is likewise universal.
These two sentences are the only prompt text that changed; the corpus hash
moved for that reason alone and was checked line by line (new baseline
`ba05b3dd992407ad937bba2836ff0acb47a19e67c848bf712e0be8d33ba767c4`).

**The guard runs where the replies are made.** After the model answers
and before anything is sent, the worker calls `ReplyPrivacyGuard::check()`
on the reply. The permitted set is built from this conversation's context
and nothing else:

- every value the model was given: the catalogue, the customer's own
  services and account, their name and number;
- every sum of the one-time items, because a correct TOTAL TO GET
  CONNECTED is kit plus installation;
- whole-month multiples of each plan price, one to twelve, because a year
  of a plan is arithmetic, not invention;
- the customer's own words, this turn and earlier, so a figure they typed
  can be echoed back;
- every number already in the system prompt, so our own phone number
  reformatted is still ours.

A reply carrying money outside that set, or an identifier, a secret or an
internal-cost phrase, is not sent. The customer receives the guard's safe
fallback ("I'm not able to complete that one automatically. I've passed it
to our team and someone will get back to you shortly."), the thread is
marked `needs_human`, the alert number is paged with the category as the
reason, and an event is appended to `ai_security_events.json` with the
conversation, channel, category and length. The blocked text is written
nowhere: not in the event, not in the log, not in the alert. If the guard
itself ever fails, the reply is withheld rather than sent unchecked.

The same call covers an external ShopBot brain when one is configured.
The brain now records the system prompt each reply was built from, and
exposes it as `lastSystemPrompt()` for the guard's public-figure reference.

## What this does and does not guarantee

Every price the customer now sees came from uCRM, was the sum of one-time
items from uCRM, was a whole-month multiple of a plan from uCRM, or was
typed by the customer. That is mechanical, not a matter of the model's
compliance.

Claims without a number are still governed by the prompt and by what
uCRM's plan records carry. The data-limit answer in the same chat is an
example: nothing in the plugin can check it, because nothing in uCRM states
it. If a plan carries a data allowance in its uCRM record, the prompt shows
it as `data limit` and the model is told to quote it exactly.

A correct reply can still be withheld: a phone number the model reformats
beyond recognition, an amount written in a form the guard cannot read. The
customer then gets the fallback and a person, which is the failure mode the
guard's author chose on purpose. Watch `ai_security_events.json` in the
first days; a block with a legitimate reply behind it is a permitted-set gap
to close, not a reason to loosen the rule.

## Tests

`tests/test_prices_are_grounded.php` (36 assertions) drives the real worker
against the fake Evolution server and the fake uCRM, with a brain that
returns scripted text and records what it was shown. It replays the 16 Sep
chat on the support number with no CRM configured: the model receives no
catalogue and a fenced prompt, the customer receives the fallback, the team
is paged with `foreign:amount`, the thread is marked for a person, one
metadata-only event is stored, and a byte search of the whole data
directory finds none of the four figures. With uCRM reachable, the support
number is handed two plans and two one-time items, and a reply made of
catalogue prices, their sum and a yearly multiple passes untouched; a figure
the customer typed passes; a price for a kit the catalogue does not list is
blocked even with the catalogue present; with the flag off, no catalogue is
fetched, the fence is there and an invented price is still blocked; the
sales number behaves as before; the account prompt is fenced and told the
customer's own amounts may be stated. Nine mutations each make the test fail
(guard call removed, support catalogue removed, fence back to sales-only,
hardware sums not permitted, customer words not permitted, event not stored,
blocked text stored, no hand-over on a block, ShopBot path unguarded).

## Server steps after upload

Confirm the version:

```
docker exec ucrm grep -c '"version": "5.18.10"' /data/ucrm/data/plugins/dishnet-hybrid-sudan/manifest.json
```

Watch the guard work (counts only; no text is ever in these lines):

```
docker exec ucrm sh -c "grep -c 'reply BLOCKED by guard' /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/ai_platform.log; grep -E 'in channel=support' -A1 /data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/ai_platform.log | grep -c 'catalogue loaded'"
```

The first number is blocks so far; the second is support-channel turns that
received the catalogue, which was zero before 5.18.10 and should grow with
every support-channel message.

And the ledger of blocks, metadata only:

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php -r 'require "lib/bootstrap_data.php"; require "lib/StoreInterface.php"; require "lib/SqliteStore.php"; $s = SqliteStore::create(cliDataDir(__DIR__)); foreach ((array)($s->load("ai_security_events.json") ?: []) as $e) echo $e["at"], "  conv ", $e["conversation_id"], "  ", $e["channel"], "  ", implode(",", $e["categories"]), "  len=", $e["blocked_length"], PHP_EOL;'
```
