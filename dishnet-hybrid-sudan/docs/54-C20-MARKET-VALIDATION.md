# 54 — C20 Market Validation Instrument

**Status:** FIELD INSTRUMENT — for use before deciding C20
**Purpose:** Produce the one fact docs/52 §6.1 identified as decisive
**Changes made:** NONE.

---

## 1. A correction to the draft questionnaire, before it is used

The draft's instinct is right — *"Do not tell them about A/B/C first. Otherwise you're
leading the answer."* But **three of the twelve questions are A, C′ and B in disguise,
asked in that order:**

| Draft | What it actually asks |
|---|---|
| Q6 *"Would you replace your existing router with a DishNet-provided MikroTik if it gave you automated voucher management and reporting?"* | **Model A** — and it bundles the cost (replace) with the benefit (voucher management) in one sentence |
| Q7 *"Would you prefer DishNet to configure your existing MikroTik?"* | **Model B / C′** |
| Q8 *"Would you allow a DishNet technician to visit and configure it?"* | **Model C′** |

Two problems, and they compound:

1. **They are hypothetical preference questions.** People agree to hypotheticals they would
   never buy. *"Would you like a better thing at no stated cost?"* has one answer.
2. **The order embeds the conclusion.** Asked after Q6's "replace your equipment", Q7's
   "we'll configure what you have" is obviously more attractive. Nearly everyone will
   choose Q7. That is a result about question order, not about the market — and it points
   at B, the most expensive answer architecturally.

**Q1–Q5 and Q9–Q12 are good.** They ask about observable facts, which is what this exercise
needs. The fix is to keep those, sharpen them, and replace Q6–Q8 with evidence of **past
behaviour** rather than stated intent.

---

## 2. The thing actually being measured

Not *"do they own a MikroTik."* That question is too coarse: a MikroTik acting as a plain
router is trivially replaced; one running their hotspot, firewall and VPN, configured years
ago by someone who has left, is not.

**The measure is: is there a router whose replacement would cost them something?**

Three findings decide C20, and only the first is in the draft:

| | Finding | Why it decides |
|---|---|---|
| **M1** | Is there a **load-bearing** MikroTik? | If no → A imposes little friction |
| **M2** | Is it **certifiable** — model and RouterOS version? | An eight-year-old unit on v6 is not a C/C′ candidate at all |
| **M3** | Is there an **incumbent** — IT person or contractor? | A stakeholder who may resist, or may be the channel |

M3 is absent from the draft and is a strong predictor. If a contractor maintains their
network, that contractor is either an obstacle to DishNet or a distribution partner — and
which one is worth knowing before designing onboarding.

---

## 3. The instrument

Ask in this order. **Do not describe the product before Section D.**

### Section A — connection *(facts)*
1. What internet connection do you have here? *(Starlink / Fiber / LTE / other)*
2. Who do you buy it from, and roughly how long have you had it?
3. Do you have more than one location? How many?

### Section B — equipment *(facts — the M1/M2 core)*
4. What equipment sits between that connection and your Wi-Fi? *(Let them describe it. Ask
   to see it if you are on site — a photo of the label is worth more than an answer.)*
5. Is any of it MikroTik? **Record the exact model and RouterOS version from the label or
   Winbox** — not "a MikroTik."
6. What is that device doing today? *(Just routing / Wi-Fi / guest hotspot / firewall / VPN
   / something else — prompt for each; do not accept "everything")*
7. If that box died tonight, what would stop working?  ← **this is M1**
8. Who set it up? Who would you call if it broke?  ← **this is M3**
9. Has anyone changed its configuration in the last year? Who?

### Section C — the Wi-Fi business *(facts)*
10. Roughly how many guests use your Wi-Fi on a normal day? On your busiest day?
11. Do you charge for Wi-Fi? *(If yes: how — code at reception, password, something else?)*
12. If you sell access, how do you produce the codes today, and who does it?
13. In the last year, have you paid anyone to work on your network? Roughly how much?
    ← **willingness to pay, from behaviour rather than intent**
14. What is the single biggest problem with your Wi-Fi as it stands?

### Section D — *only now* describe the product, then ask
Describe it once, plainly: *automated voucher management, your own prices, reports, and
DishNet manages the technical side.* Then:

15. Does that sound useful here? What would you use it for first?
16. What would have to be true for you to try it?  ← **open. Let them raise hardware, price
    or trust themselves. Whatever they name first is the real obstacle**
17. *(Only if they have not raised it)* If it needed a specific router, how would you feel
    about that?

**Q16 replaces the draft's Q6–Q8.** Instead of offering three options and recording a
preference, it lets the obstacle surface unprompted. If hardware is the barrier, it comes up
here without being suggested. If it never comes up across twelve interviews, that is a
stronger result than twelve people agreeing to a hypothetical.

### Record for every interview
Business type · locations · connection · **exact MikroTik model + RouterOS version** ·
what it does · M1 load-bearing yes/no · M3 incumbent yes/no · charging for Wi-Fi yes/no ·
first obstacle named at Q16.

---

## 4. The decision rule — set before the data is collected

Twelve interviews will not give statistical confidence, and do not need to: the thresholds
here are coarse. What matters more is that **the rule is fixed in advance**, because the
temptation to read an ambiguous result in favour of an answer already preferred is strongest
after the data arrives.

**Primary measure: of those interviewed, how many have a load-bearing, certifiable MikroTik
(M1 and M2 both true)?**

| Result | Reading | C20 implication |
|---|---|---|
| **0–3 of 12** | Most prospects need networking equipment regardless | **A imposes little friction.** Proceed with A; C20 effectively settled |
| **4–7 of 12** | The market is genuinely split | **Segment.** Serve the no-router half with A now; the other half informs C′ later. A is not blocked |
| **8–12 of 12** | Replacement is a real sales obstacle | **A is a commercial problem.** Evaluate C′ first — it costs field operations, not architecture. B only if C′ is impossible |

**Secondary readings, recorded but not decisive:**

- **M3 high** (most have an incumbent contractor) → a channel question, possibly a partner
  strategy. Also raises the certified-installer idea behind C′.
- **Q13 mostly zero** (nobody has paid for network work) → a pricing signal for C11, and a
  warning that the platform may need to sell itself on revenue gained rather than cost saved.
- **Q11 mostly "no charge"** → the reseller model is a behaviour change, not just a tool.
  That is a harder sale than it appears and belongs in C11.

### 4.1 What would make the result untrustworthy

- Fewer than 8 interviews, or all from one town or one business type.
- Interviews conducted after describing the product — Section D must stay last.
- "A MikroTik" recorded without model and version: **M2 cannot be evaluated**, and the
  8–12 row cannot be distinguished from the 0–3 row.

---

## 5. Why B stays parked either way

Even at 12 of 12, the first alternative is **C′**, not B:

| | C′ | B |
|---|---|---|
| Serves a customer who already owns a certified router | ✓ | ✓ |
| New architecture required | **None** | Eight pieces, incl. a research problem |
| Trust anchor | Preserved | Rebuilt |
| Recovery baseline | Preserved | **Does not exist** |
| Cost | Field operations, scales with headcount | Engineering, then scales without people |

**B is only indicated if C′ is operationally impossible** — no technician coverage where the
customers are. That is a question about DishNet's field capability, and it can be answered
independently of, and in parallel with, these interviews.

This is consistent with docs/53 §4: BYO is not built speculatively.

---

## 6. What this exercise cannot settle

C11 pricing, C17 wording, C9 (portal as shop), C14 (merchant of record). The interviews will
produce *signals* for C11 — Q11 and Q13 especially — but the pricing unit still follows C20,
per docs/51.

*No production system was contacted. No prototype, schema or architecture was changed.*
