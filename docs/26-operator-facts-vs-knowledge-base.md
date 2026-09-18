# 26 — The facts that were never in the prompt

**Date:** 18 September 2026 · **Plugin:** 5.18.21 · **Status:** built, pending upload

## What was wrong

`DishNetAiBrain::coverageRules()` was an if/else, and `localFacts()` had
exactly one call site — inside the `else`. The Uganda install has **34
knowledge entries seeded**, confirmed by a read-only query against the live
database. So the `else` never runs, and every business fact set from
`tools/set_config.php` reached nothing:

| Setting | Configured | In the prompt |
|---|---|---|
| `ai_fact_payment` | 17 Sep — the Ecobank UGX account | never |
| `ai_fact_office` | 17 Sep — Acacia Mall, Kampala | never |
| `ai_fact_delivery` | 17 Sep | never |
| `ai_fact_prices` | 16 Sep — the VAT line | never |
| `ai_fact_location_pin` | the map pin | never |

**Three releases were written against a dead path.** 5.18.14 made the payment
fact settable, 5.18.15 added the placeholder refusal, 5.18.16 made the fact
quotable past the guard's leak rule. All correct, all inert here.

And the diagnosis that produced them was wrong. The customer who asked "will
pay in which number" was refused by a knowledge-base rule,
`RULE_PAYMENT_SAFETY` — *"Payments only via the official DishNet payment
details on the invoice"* — not by the `ai_fact_payment` default. Doc 22
carries that correction.

## What this release changes

One idea: **a knowledge base is company policy; the operator facts are this
deployment's configuration; neither supersedes the other.** The comment above
the if/else called the legacy block a fallback "for installs that have not
seeded a knowledge base" — right about the South Sudan coverage paragraphs,
wrong about `localFacts()`.

`businessFactsBlock()` is now called from **both** branches. The legacy
coverage text stays in the `else` where it was, so an install with no
knowledge base produces the byte-identical prompt it always has — verified by
diffing the generated prompt before and after.

**The knowledge-base branch asks for operator-set facts only** —
`businessFactsBlock(false)`. The built-in defaults are South Sudan's: a Juba
office, kits crossing at the Joda border, a South Sudan pay page. They are a
fallback for an install with no knowledge base. Emitting them on a
knowledge-base install would push Juba into a prompt whose knowledge base
already answers the office question for its own country — recreating, one
paragraph lower, the conflict this release removes. A fact nobody configured
is simply absent, and when none are configured the heading is absent too.

## What it does NOT change

**It does not by itself make the assistant give out the bank account.** The
prompt will now contain both the configured account *and*
`RULE_PAYMENT_SAFETY` telling it to use the details on the invoice. This
codebase already knows what happens then — from `seed_knowledge.php`'s own
header:

> "TBC_SLA_STATIC_IP put 'public/static IP availability' on the
> never-improvise list while BUSINESS_PLANS stated a public IP as a feature of
> Business — so the AI was told to answer and to refuse the same question, and
> took the safer branch."

Same shape. Rewording that rule is a knowledge-base edit, proposed separately
and awaiting approval. Nothing in this release touches knowledge content.

## Prompt corpus

**Changed, deliberately**, from `ba05b3dd` to `f770081e`. The corpus config
sets `knowledge_block` on all 2400 prompts, so every one of them takes the
branch this release fixes. The diff is exactly the six lines of the business
facts block. The legacy path, which the corpus does not exercise, is
byte-identical.

## Tests

`tests/test_operator_facts_reach_prompt.php` — 29 assertions in eight
sections: all five facts present with a knowledge base; the knowledge base
intact and printed first; the legacy path unchanged; a knowledge-base install
with nothing configured getting no Juba, no Joda, no pay page and no empty
heading; a South Sudan install with nothing configured still getting its
defaults; one fact set not dragging in the others; `omit` still silencing a
fact; the verbatim payment instruction still attached; and both call sites
wired.

Four mutations fail it: the old if/else restored, the knowledge-base branch
asking for defaults, the empty-block guard removed, and the legacy branch
losing its facts.

Full suite: 179 suites, 6646 assertions, 0 failed.

## How this was missed

The if/else is four lines and reads like an addition. Three releases were
written against `localFacts()` without once checking whether it ran on the
install they were for. The check that found it took one read-only query, and
it should have been the first thing done rather than the fourth.
