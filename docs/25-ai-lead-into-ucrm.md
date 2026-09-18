# 25 — The lead that stopped at leads.json

**Date:** 18 September 2026 · **Plugin:** 5.18.19 · **Status:** built, pending upload, **switched OFF**
**Phase:** 2 of 3. Phase 1 (location) shipped in 5.18.18. Phase 3 (quotation) is not started.

## What was happening

The assistant has written qualified leads since 5.18.3. `AiLeadService` has a
two-gate qualifier, phone deduplication, merge-don't-clobber, an audit trail
and a conversation link. All of it correct, and all of it writing to one file
the sales team's own system has never read.

`leads.json` is the plugin's. uCRM is where the business works. Nothing carried
one into the other, so a conversation that became a real opportunity — name,
location, plan, "yes please quote me" — existed only in a plugin screen.

## What 5.18.19 does

`lib/UcrmLeadSync.php` creates the lead in uCRM as a **lead client**
(`isLead: true`), using `CrmApiClient` and the payload `cron/kyc_crm_sync.php`
has been creating real customers with for months. It is not a new API client
and not a new payload shape — it is the same call from a different trigger.

**On the queue, never inline.** `AiReplyWorker` emits `crm.lead.sync`;
`UcrmLeadWorker` drains it on the same spawn. A customer must never wait on
uCRM for their reply, and a uCRM that is restarting must not cost us the lead.
The queue already provides retries with backoff, a dead letter after five
attempts, and a record — none of that needed inventing.

**Off by default.** `ai_crm_lead_sync` is absent, which means off, like every
AI feature in this plugin. Shipping is not enabling.

## Idempotence, because a queue retries

EventBus retries whenever uCRM times out *after* doing the work, so "created
once" cannot be a property of the happy path. In order:

1. the lead already carries a `crm_client_id` → **PATCH**, never POST
2. uCRM has exactly one client on that phone → **LINK** and write the id back
3. uCRM has **more than one** on that phone → stop, flag, create nothing
4. nobody has it → **POST once**, under a lock that re-reads the lead

Step 4's lock re-read is the real guard, and deliberately so: two workers that
both read "no crm_client_id" must not both create. Step 1 is the cheap check in
front of it.

**Step 3 is the one that matters.** `DishNetTools::identifyCustomerByPhone`
refuses to choose between two clients sharing a number, and this inherits that
refusal rather than re-deciding it. Attaching a conversation to the wrong
customer is worse than attaching it to nobody; a duplicate client is worse than
a lead that waited. The lead is flagged `ambiguous_phone` for a person.

uCRM's search is also fuzzy, so a returned row is only a match once its
contact's digits actually match. Trusting the endpoint would link a lead to a
stranger.

## organizationId and countryId are evidenced, not copied

The KYC payload carries `organizationId => 2` and `countryId => null` as
literals. `tools/org_probe.php` exists because those two numbers are genuinely
ambiguous on this install — the code documents organizations 2 and 7, the admin
screen shows id 1 — and its conclusion was that **the clients' own
organizationId is the answer that needs no guess**.

So this asks the same question the same way, at run time: read the clients this
install has and take the commonest value. `ucrm_lead_organization_id` and
`ucrm_lead_country_id` override it. When neither can be established it
**refuses** and says so, rather than falling back to a literal — a lead filed
under the wrong company is a lead somebody has to find and move.

## An outage is not an answer

The first version of this had the same bug in two places, and the tests found
both.

`CrmApiClient::get()` returns `null` for a transport or HTTP failure and `[]`
for a genuine empty result. The code treated them alike. That meant:

- a uCRM outage read as **"nobody has this number"** — and then a duplicate
  client for a customer uCRM already had, the moment it came back;
- a failed defaults read reported as **"cannot establish organizationId"**, a
  *decision*, which the worker does not retry — so a uCRM that was merely
  restarting would drop the lead out of the queue permanently.

Both are now distinguished. A failure the system could not even ask about is
`failed` and retried; a decision it made on good information is `skipped` and
not. The two words are load-bearing, and the worker's branch on them is
asserted.

The whole-uCRM-down case hid the first bug, because the defaults read failed
too and produced the right outcome by accident. The test that catches it puts
**only** the phone search down, with the defaults configured — which is the
shape a real partial outage takes.

## What reaches a salesperson

The client carries the name split for uCRM, the phone on a contact, the typed
location as `street1`, the email and company when given, and a note holding
what they asked for, their type, the plan and hardware discussed, whether a pin
was received, and — in capitals — whether they **asked for a quotation**.

Coordinates go on separately as `gpsLat`/`gpsLon`, the mechanism the support
app has used for site visits since it started capturing them.

**The patch is deliberately narrow.** It writes coordinates and nothing else. A
salesperson who corrected a name in uCRM must not have it undone by the next
message the customer sends — the same rule `AiLeadService::merge()` has always
followed.

A customer who gave no name is filed under their number, never under an
invented surname a salesperson would then greet them by.

## Tests

`tests/test_ucrm_lead_sync.php` — 54 assertions, driven through the real
`CrmApiClient` against the fake uCRM, which was extended with client create,
search and patch and **refuses unknown fields** the way the real one does
(5.18.11 shipped a payload with one extra key and every create 422'd).

Twelve sections: off by default; the create and every field on it; the
evidenced organization and the config override; the GPS patch and its
narrowness; three consecutive syncs producing one client; linking a known
number; refusing two clients on one number; a fuzzy hit that is not a match; a
whole uCRM outage; **a lookup outage with the defaults known**; a lead with no
phone; a nameless customer; the strict payload; and the reply path queueing
rather than calling.

Six mutations fail it: ambiguity resolved by taking the first match; an outage
read as no-match; organizationId hardcoded to the KYC literal; the patch
widened to overwrite a name; the reply path not queueing; and — after the gap
above was closed — a lookup failure treated as an answer.

One mutation deliberately does **not** fail it: removing the early
`crm_client_id` check. The lock's re-read still prevents the duplicate, which
is defence in depth working, not a hole.

Full suite: 178 suites, 6609 assertions, 0 failed. Prompt corpus unchanged
(`ba05b3dd`) — nothing in this touches the prompt.

## Switching it on

```
docker exec ... php tools/set_config.php --key ai_crm_lead_sync --value 1
```

Before that, `tools/org_probe.php` is worth running once: it is read-only and
shows which organization the clients actually belong to, which is what this
will file new leads under.

## What this does NOT do

- No quotation, no PDF, nothing sent to a customer. Phase 3, with its own
  approval gate.
- No uCRM **customer** — a lead is `isLead: true`. uCRM converts one when it is
  invoiced, and `CrmApiClient::post()` already does that before a payment.
- Nothing is written back from uCRM into the plugin except the client id.
- An existing uCRM record is never rewritten beyond its coordinates.
