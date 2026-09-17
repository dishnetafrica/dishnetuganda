# 22 — The sale that stopped at "which number do I pay to?"

**Date:** 17 September 2026 · **Plugin:** 5.18.14, corrected by 5.18.15 · **Status:** live

## What happened

At 00:55 a customer asked how to get Starlink. The assistant quoted the kits
and the plans correctly from uCRM, built the total to get connected
correctly, and the customer said "Okay l will pay in process boss". At 00:59
they asked the last question of any sale: "Tell me will pay in which number".

The assistant answered: *"I can't provide payment details directly. Please
refer to the invoice you receive after confirming your order for the
official payment details."* There was no order and therefore no invoice. The
sale stopped there.

## Why

That refusal is not a defect in the model. It is the **default text of the
`ai_fact_payment` business fact**, written for South Sudan:

> customers pay online at `https://dishnetafrica.com/pay.html` … **NEVER
> share bank details or account numbers in chat.**

And nothing could change it. The key was absent from the uCRM Configuration
screen, from the Engage → WhatsApp → AI setup tab, and from
`tools/set_config.php`. The same is true of `ai_fact_office` and
`ai_fact_delivery`, which is worse than it sounds: left unset, a Ugandan
customer asking where we are is told the office is in Juba, South Sudan, and
one asking about delivery is told their kit is flown to Renk and crosses at
the Joda border.

## What 5.18.14 changes

**The three business facts become settable**, from `tools/set_config.php`
like every other operator setting: `ai_fact_payment`, `ai_fact_office`,
`ai_fact_delivery`. Each shows its South Sudan default in the listing, so it
is obvious which are still answering as another country. `omit` drops a fact
entirely, which beats saying the wrong thing while the right words are being
decided.

**A payment fact is quoted verbatim.** When `ai_fact_payment` is set, the
prompt adds: write any account number, till number or address in it exactly
as written, character for character, never reformat, never add or remove
spaces, never shorten; an uncertain digit is a hand-over, not a guess. Only
that fact gains the instruction. It exists because of how the guard behaves,
below.

**Nothing else changed.** The prompt corpus hash is unchanged: the new
sentence appears only when an operator has set the fact.

## Why this is safe to switch on

`ReplyPrivacyGuard` has enforced the rule since B3 and 5.18.10 put it on the
live reply path: a figure the prompt contains may be repeated, every other
figure is refused and the whole reply is replaced by the safe fallback with
a hand-over to a person. Tested against a real configured account:

| Reply | Outcome |
|---|---|
| the configured account number | sent |
| a different account number | blocked, `foreign:phone` |
| the configured account, re-spaced | blocked |
| no figure at all | sent |

The second row is the one that matters. An account number the model invents,
or one a customer talks it into, does not reach anybody: a customer's money
cannot be redirected by a conversation. The third row is why the prompt
insists on verbatim — a correct answer withheld is a smaller failure than a
wrong one sent, but it still costs the customer their answer, so the model
is told plainly not to retype the number its own way.

The assistant's copy of the account number lives in exactly one place, the
config value: not in the plugin code, not in the catalogue, not on the
website. The company's banking details do also appear as the shipped
defaults in `tools/set_email_brand.php`, which is where the quotation and
invoice PDF templates read them from — that is the right place for them and
always was. It does mean there are two copies to keep in step, so a change
at the bank is a change in both: the branding defaults and this fact.

## Setting it

The account number is not repeated in this record. Read it off the company's
own quotation, a bank statement, or the branding defaults in
`tools/set_email_brand.php` at the moment of setting — the value on the
quotation the customer already holds is the one they must pay into, so the
quotation is the better source of the two. What follows is the **shape** of
the command, not a command to run:

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/set_config.php --key ai_fact_payment --value "Pay by bank transfer to <ACCOUNT NAME> at <BANK>, <BRANCH>, <BANK ADDRESS>. UGX account number <NUMBER>. Enter the account name and number exactly as written. Use your own name as the payment reference, then send the transfer confirmation here so we can match it to your order."
```

Since 5.18.15 that exact text is **refused**: every `<...>` has to be
replaced with a real value first. See below for why that refusal exists.

Read the number back against a bank statement before leaving the terminal:
the assistant repeats it character for character, and a digit changed at the
bank without changing it here is a customer paying into an account that is
no longer ours.

**One account, not two.** The company holds a UGX account and a USD account
whose numbers differ only in the final digit. Only the UGX one belongs in
this fact. Two near-identical numbers in the same prompt is an invitation to
transpose them, and a customer who pays the right amount into the wrong
account of ours has still lost their money for a fortnight. International
payers are a hand-over to a person, which is what the assistant does anyway
for anything this fact does not answer.

Check the other two while there — unset, they are still answering as South
Sudan:

```
docker exec -u $(stat -c %u:%g /home/unms/data/ucrm/ucrm/data/plugins/dishnet-hybrid-sudan) -w /data/ucrm/data/plugins/dishnet-hybrid-sudan ucrm php tools/set_config.php | grep -A2 "ai_fact_"
```

## 5.18.15 — the template that went live

The command block above, in its 5.18.14 form, was written as an example with
`<BANK>`, `<NUMBER>`, `<BRANCH>` and `<TILL>` in it. It was pasted into the
terminal and run exactly as published, and the setting saved: the assistant
was then configured to tell paying customers to pay into `account <NUMBER>`
at `<BANK>`. Worse than the refusal it replaced, and entirely my doing — a
deploy note that can be copied and run *is* copied and run, which is the
whole point of writing self-contained commands.

Three things were wrong and only one of them was the operator's keyboard:

- the example was indistinguishable from the real commands around it — same
  block, same prefix, same shape;
- nothing between the paste and the customer looked at the value. The
  account-number warning fired and was printed, but a warning after a
  successful save is a note, not a stop;
- the tool had no idea what a placeholder was.

`tools/set_config.php` now refuses any value containing an angle-bracketed
run of capitals, before it writes anything, on every key:

```
  That still has the example placeholder <BANK> in it, so nothing was saved.

  Customers would have read it exactly as typed. Replace every <...> with the
  real value and run it again, or use --clear to leave the setting unset.
```

It exits non-zero and the previous value stands. The check is deliberately
crude — `<` followed by capitals is never something a customer should read,
in any of these settings — and deliberately a refusal rather than a warning,
because the failure it prevents is silent: a saved placeholder looks exactly
like a saved value in the listing, and only a customer finds out.

The documentation fix matters as much as the code one. Example values in
these records now say so in the prose above the block, and the one command
worth running is the one with real values in it.

## The part the plugin cannot fix

The transcript is headed **Secure-Africa Solutions Limited**, because the
WhatsApp Business greeting on that number still carries another company's
name. 5.18.9 stopped that greeting silencing the assistant, but it is still
the first thing every new customer reads. A customer greeted by one
company's name and then given a bank account in another company's name is
being shown the shape of a scam, however honest both are. Switching the
greeting off, in WhatsApp Business → Business tools → Greeting message, is
worth doing before the account number goes live.

## Tests

`tests/test_payment_details.php` (24 assertions): the default refusal and
the South Sudan pay page when unset; the operator's text replacing both
entirely when set; the verbatim instruction present for payment and absent
from every other fact; the guard sending the configured account, blocking a
different one with the fallback, blocking a re-spaced one, and leaving a
figure-free reply alone; and all three keys managed by the settings tool with
their warnings. Since 5.18.15 it also asserts that a placeholder value is refused, that
nothing is saved when it is, that the message names `--clear`, and that the
check runs before any write. Five mutations each fail it: the verbatim rule
dropped, the operator text ignored, the key unmanaged, the guard's identifier
check disabled, the placeholder refusal removed.

## Two rotted tests, found and fixed on the way

The suite went from green to eight failures between yesterday's release and
this one, in `test_alert_cooldown_record.php` and `test_followup_lifecycle.php`
— neither touched by this work. Running both at the previous commit with the
current clock reproduced the failures exactly, which ruled the change out.
Both were hard-coded dates that had simply expired:

- the alert test recorded a cooldown at a fixed epoch second from 10
  September and read it back. `AlertService::recordSent()` prunes rows older
  than seven days, so from 17 September the write was discarded by its own
  housekeeping the moment it landed;
- the follow-up test's CASE J passed a fixed `now` of 14 September while its
  conversation fixture is built from the real clock (quiet 48 hours ago). As
  real time moved past that date the fixture overtook the fake present, the
  conversation read as still live, and the gate answered
  `active_conversation` instead of the quiet hours the case exists to test.

Both now derive their times from the clock: "an hour ago" and "today at
20:30 UTC". A test that expires is worse than no test, because it spends its
last days reporting a fault that is not there.
