# 109 — Q7: the evidence search, and its result

**Evidence report only. No migration, no schema change, no application code, no
production connection, no uCRM installation.** No implementation plan.

> **Q7.** When DishNet onboards a HotSpot operator, does the uCRM
> customer/service record exist **before** the Domain-B HotSpot service is
> created, or can Domain-B onboarding legitimately happen first?

---

## 1. What was excluded, deliberately

Per instruction, none of the following was used to infer an answer:

| Excluded source | Why it would have been wrong |
|---|---|
| the sibling plugin's `lte_service_links` | a different product line (Sudan LTE), and it links a uCRM **client**, not a service |
| any table definition | a schema records a past design choice, not a sales workflow |
| the simulator | synthetic by construction |
| the proposed architecture (`docs/99`–`108`) | that is the thing Q7 is supposed to decide |
| technical convenience | gating at creation is cheaper; that is not evidence |

`docs/108` already recorded the first of these as a **prior, not an answer**.
This document does not upgrade it.

---

## 2. What was searched

Operational rather than structural sources: the deployed sales automation, the
live billing/accounting code, the public website, deployment runbooks, and the
server scripts.

---

## 3. Findings — five, all measured

### F-1 The deployed sales assistant does not sell HotSpot

`n8n/DishNet_Uganda_AI_Bot_v1.0.json`, the live Uganda assistant's system
prompt, states the product scope in its own words:

> *"We sell **Starlink** — kits and monthly internet plans — to homes and
> businesses anywhere in Uganda. We do NOT sell fiber, and we do NOT sell SIM
> cards."*

**HotSpot is not in the sales flow.** Every price the bot quotes comes live from
the billing system, and the product scope is Starlink.

### F-2 No HotSpot or MikroTik revenue path exists in the live stack

Searched `dishnet-hybrid-sudan/lib/`, `dishnet-hybrid-sudan/src/` and
`dishnet-ai/lib/`. The **only** occurrence of *MikroTik* is in
`HardwareKnowledge.php:145` — a list of vendor names the assistant may answer
questions about (*"MikroTik, UniFi, Ubiquiti, Fortinet, Sophos, Cisco,
TP-Link"*), i.e. hardware advice, **not a billed product**.

### F-3 No uCRM service plan for HotSpot appears anywhere

No `servicePlan` / `service_plan` reference in the live plugins names HotSpot or
MikroTik. The plugins do read `clients/services?clientId=` (`docs/100`), so the
capability exists — **nothing indicates a HotSpot plan to read.**

### F-4 The website markets HotSpot as an enquiry, not a product

`dishnet-web-uganda/site/hotspot.html` exists, alongside
`blog-wifi-hotspot-business-uganda.html`. Measured content: **vouchers**
mentioned, **contact** call-to-action ×3, and **no price of any kind**.

It is a **lead-generation page**. Every enquiry becomes a human contact, not a
self-serve purchase.

### F-5 The repository records its own uncertainty about whether it is sold

`dishnet-web-uganda/README-DEPLOY.md`:

> *"The site advertises Starlink, Fiber, WiFi/hotspot zones, and CCTV/security…
> **If any of these is not actually sold in Uganda yet**, remove the page and its
> nav/footer links before launch — an advertised service nobody can buy costs
> trust."*

**Written as an open caveat.** The repository does not know whether the HotSpot
product is sold.

---

## 4. Verdict — **Q7 is NOT answered**

> The repository has been searched and **cannot** answer Q7.

And the search changed the shape of the question. Combining F-1…F-5 with what
`docs/78`/`96`/`103` already establish — Domain B has never run in production —
gives:

> **Q7 is not an archaeological question about what DishNet has done. There is
> no onboarding history to recover, because the product is at the enquiry stage
> and the platform that would onboard an operator does not exist.**

That matters for what input is actually needed. Asking *"what do you currently
do?"* presumes a practice. The honest question has **two branches**, and only
the operator knows which applies:

| | Branch | Then Q7 is |
|---|---|---|
| **A** | DishNet **already** runs HotSpot sites manually — a MikroTik sold, configured by hand, billed through uCRM | a question about **existing practice**: what gets created first today? |
| **B** | No HotSpot operator has been onboarded yet | a **forward-looking business decision**, not a recollection |

**Nothing in the repository distinguishes A from B**, and it would be an
inference to pick one.

---

## 5. One checkable fact would resolve most of it

Rather than a process interview, a single lookup the operator can perform
directly in uCRM:

> **Does a HotSpot / MikroTik / WiFi-zone *service plan* exist in uCRM today, and
> does any client hold a service on it?**

| Answer | What follows |
|---|---|
| **No plan exists** | uCRM has nothing from which a HotSpot service record could be created, so for the first operator a uCRM service **cannot** precede the Domain-B service. Branch **B**; Q7 tends to **"Domain-B may legitimately be first"** — but it is then a decision to ratify, not a fact |
| **A plan exists, clients hold it** | Branch **A** — the practice already exists and Q7 should be answered from those clients' records: which was created first |
| **A plan exists, no client holds it** | prepared but unused; still branch **B** |

This session **cannot** perform that lookup: no uCRM access, egress 403, and
production probing is forbidden. **It is one screen in uCRM for the operator.**

---

## 6. Exact operator input required

1. **Has DishNet ever onboarded a HotSpot/WiFi-zone operator?** (branch A or B)
2. **Does a HotSpot service plan exist in uCRM?** — the §5 lookup.
3. *If A:* for an existing HotSpot customer, **which record was created first** —
   the uCRM client/service, or the equipment/site?
4. *If B:* when the first operator is onboarded, **is the uCRM client and service
   expected to be created before the Domain-B service, or is Domain-B-first
   acceptable?**

Answering **2** alone very likely settles it.

---

## 7. Status after this search

| | |
|---|---|
| **Q7** | **OPEN** — unanswerable from the repository; reduced to one uCRM lookup |
| **U-1** | **OPEN**, unchanged. Not closed, not narrowed by assumption |
| **U-5** | **OPEN** — and see §8 |

**No gate moved. Nothing implemented.**

---

## 8. Two observations worth recording

### 8.1 U-5 may have nothing to link to yet

If no HotSpot service plan exists in uCRM (F-3 found no evidence of one), then
the uCRM **service** link U-5 contemplates has **no counterpart to reference**.
That does not decide U-5 — it means U-5's premise needs checking before it is
designed further. **Recorded, not concluded**; the repository is not uCRM's
database.

### 8.2 A divergence to settle — not resolved here

The stated identity boundary is that uCRM *"must not be a prerequisite or
dependency of"*:

```
voucher issuance → portal redemption → AAA publication
  → RADIUS authentication → HotSpot session → accounting
```

`docs/107` §9.2 proposed the opposite for the **first** link in that chain: that
plan creation and voucher issuance, being revenue-bearing, should require the
uCRM customer link.

There is a real distinction that may dissolve it:

| | |
|---|---|
| **A runtime dependency** — calling uCRM during the operation | **FORBIDDEN**, and already settled (`docs/102`): redemption and accounting must never consult uCRM. Response uniformity is not tradeable, and an unknown code resolves no customer |
| **A stored-link precondition** — reading a local column written earlier | different in kind: no network call, no latency, no uCRM availability coupling |

**But it is still a prerequisite in effect** — issuance would fail for an
unlinked customer. **Whether that is wanted is a business decision, and it is
yours, not mine.** Flagged rather than silently resolved in either direction.

**Redemption onward is not in question**: `docs/102` already classifies uCRM
there as **FORBIDDEN, not merely unnecessary**, and nothing here changes that.

---

## 9. The three identities — preserved

| | Identity | Requires uCRM? |
|---|---|---|
| 1 | **Domain-B customer / operator** — the HotSpot platform customer | **only where §8.2 is settled that way** |
| 2 | **uCRM customer / service** — the DishNet commercial relationship | by definition, where one applies |
| 3 | **Guest / voucher user** — a transient HotSpot identity | **NEVER** |

Identity 3 has no `mt_customers` row, no principal, no login and no actor kind —
`docs/89` settled that the attempt store gives it attribution **without**
pretending it is a `principal` or `staff` actor, and **no `guest` actor kind is
to be added**.

> **`mt_customers` existing is not a reason to make uCRM mandatory.**
> `mt_customers` is the Domain-B **authorization boundary** (`docs/101`) — the
> thing RLS keys on. It carries a uCRM relationship when one exists; it is not a
> projection of uCRM and is not evidence that one is required.

---

**Stopping here, as instructed. Q7 is not answered. U-1 and U-5 remain OPEN.
Suite unchanged: 1,599 assertions, 27 suites. No migration, no schema change, no
application code, no production connection, no uCRM installation, no
implementation plan.**
