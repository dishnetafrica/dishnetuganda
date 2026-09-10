# Plan recommendation: what decides, and where it lives

Two rules changed. Committed to `claude/study-this-jhe2eg`, **not deployed** —
the container still serves the previous commit until you deploy it.

---

## 1. Where the logic lives

| Concern | File | Gate |
|---|---|---|
| Qualification + plan choice | `lib/DishNetAiBrain.php` → `qualification()` | `ai_qualification` |
| Hardware choice | `lib/DishNetAiBrain.php` → `hardwareBlock()` | `ai_hardware_expert` |
| Plan names and prices | uCRM `service-plans`, via `DishNetTools::getProducts()` | — |
| Public-IP knowledge | `knowledge_items` → `PUBLIC_IP`, `BUSINESS_PLANS` | admin-editable |

One method, one prompt block, no new architecture. Both gates are already ON in
production.

---

## 2. The decision hierarchy

```
What are they actually trying to do?
        │
        ├─ How heavily will they use it?          ─┐
        │   people, devices, what runs on it       │  two separate
        │                                          │  questions
        └─ Do they need anything ONLY a            │
           Business plan gives — a public IP?     ─┘
                    │
        ┌───────────┴────────────┐
        │                        │
   NOT required              REQUIRED
        │                    (remote CCTV, VPN into
        │                     the network, server,
        │                     remote desktop, hosting,
        │                     remote monitoring, access
        │                     control, public-facing
        │                     services, linked sites)
        │                        │
   A RESIDENTIAL PLAN,      DishNet Business
   however commercial       (Starlink Local Priority)
   the customer is               │
        │                        ├─ price in PLANS?  → quote it
        ├─ heavy use            └─ not in PLANS?    → confirm + escalate,
        │  several people/devices,                     never estimate, never
        │  work from home, video calls,                derive from Residential
        │  streaming, learning, gaming,
        │  cloud apps, small office
        │        → HIGHER-CAPACITY residential
        │
        └─ genuinely light use, or price
           stated as the constraint
                 → the lighter, cheaper one

           …and say WHY, in one sentence tied to
           what they told you.
```

**Customer type is not a node in this tree.** "We are a business" enters at the
public-IP question like anything else.

---

## 3. Rules changed

| Before | After | Why |
|---|---|---|
| "An organisation (office, hotel, lodge, factory, school, NGO, bank…) is **never given Residential as the default answer**." | Removed. Replaced with: most shops, restaurants, boutiques, clinics, small offices and small guesthouses **do not need** a Business plan, and quoting them one "charges them for something they cannot use". | Customer type was acting as the deciding factor. It isn't one. |
| "THESE ARE BUSINESS REQUIREMENTS, not home ones: …" | "A BUSINESS PLAN IS FOR ONE THING — a PUBLIC IP… It is genuinely needed for: …" | Same trigger list, reframed as a capability test rather than a customer-type test. |
| *(nothing)* | "WHAT DECIDES THE PLAN IS THE REQUIREMENT, NEVER THE LABEL." | New — the controlling sentence. |
| *(nothing)* | Choose between the two residential plans on use. Prefer the **higher-capacity** one for several people/devices, work from home, video meetings, streaming, online learning, gaming, cloud applications, a small office, or heavy everyday use. The lighter one for genuinely light use or a stated budget. | Previously nothing distinguished them; the model picked by price. |
| *(nothing)* | "ALWAYS SAY WHY, in one short sentence tied to what they told you." | A recommendation without a reason is a price list. |
| *(nothing)* | "Never move somebody up who does not need it, and never leave somebody on the light plan who has just described a houseful of people working and streaming. Both are the same failure — not listening." | Names both directions as errors. |
| Hotel/lodge: ask rooms, guests, POS, CCTV, backup | …plus "A small guesthouse is often a residential plan; a large property with remote-viewed cameras and a booking system is not." | Your example. |

**Unchanged:** the CCTV local-vs-remote rule (already corrected earlier today),
the Business-pricing rule (only quotable from PLANS, never estimated, never
derived from a Residential price), and the escalation path.

### No price or plan name is in the code

The block says *"Take the names and prices from PLANS"*. UGX 329,000 and
UGX 249,000 appear nowhere in it — asserted by test. Rename or reprice a plan in
uCRM and this logic follows without a deploy.

---

## 4. Test cases

`tests/test_ai_qualification.php` — **54 assertions, green**:

- the label does not decide (`NEVER THE LABEL`)
- trading is not a reason (`Being a business is not the reason`)
- shops/restaurants/boutiques stay residential, and what quoting Business costs them
- a small guesthouse is not a large hotel
- higher-capacity preferred, with all eight triggers asserted individually
- the lighter plan's own case, including a stated budget
- the recommendation carries a reason
- both directions named as failures
- **no price is written into the block**

`tests/conversation-suite.php` — live-model scenarios, run on the server with
`--only=ug_`:

| Your case | Scenario |
|---|---|
| residential heavy user → higher plan | `ug_res_heavy_user` — 6 people, WFH, video calls, Netflix |
| residential light/price-sensitive → Lite | `ug_res_light_user` — one room, WhatsApp, "cheapest" |
| business without public IP → residential | `ug_business_no_public_ip` — boutique, POS, no cameras |
| business with genuine public IP → Business | `ug_business_real_public_ip` — 8 staff, VPN to server |
| CCTV local-only → no automatic public IP | `ug_hw_cctv_local_only` |
| CCTV remote → evaluate public IP | `ug_public_ip_cctv` |
| unknown Business price → do not guess | `ug_business_price` |

Full suite: **78 files, 2428 assertions, green.**

---

## 5. +256 705 993 348 — unchanged

Nothing in this change is channel-specific. `qualification()` emits the same
block for any number that sells, decided by `ai_sales_on_all_numbers`, which was
already ON before today. The support number gains the same corrected logic it
was already running in its previous form; no routing, webhook, instance mapping
or lifecycle code was touched.

Verifiable: `tools/wa_compare.php` reports both numbers side by side.

## 6. +256 703 834 115 — same lifecycle

Same webhook, same `EventBus`, same `AiReplyWorker`, same `ConversationService`,
same prompt builder. The only difference between the two numbers remains which
config key holds the instance name — `evo_instance_sales` vs
`evo_instance_support` — and neither number appears anywhere in runtime code.
That was established by code inspection and confirmed by `wa_compare.php`, and
this change adds no branch on either.

---

## Not deployed

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && bash scripts/deploy-hybrid.sh
```
