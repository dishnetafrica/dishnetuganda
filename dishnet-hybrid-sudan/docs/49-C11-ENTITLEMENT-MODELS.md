# 49 — C11: Entitlement Models

**Status:** OPTIONS ANALYSIS — no option chosen
**Builds on:** docs/48 (three-layer model, accepted as direction)
**Changes made:** NONE. No code, schema or prototype change.

---

## 1. C10 restated

Your refinement is better than what I wrote, and it removes a real error.

> **HotSpot Reseller is a capability attached to a purchased service entitlement —
> not a global customer role.**

```
Customer  (the commercial party)
   └── Service  (what they bought)
         ├── Entitlement       ← the ceiling, attached to THIS service
         └── Capability set    ← what the PWA exposes, derived from THIS service

Customer A · Fiber only              → no HotSpot service → no reseller capability
Customer B · MikroTik HotSpot        → reseller capability, entitlement E1
Customer C · HotSpot, several sites  → reseller capability, entitlement E2 > E1
```

**Why this is materially better than "Reseller is a customer role":** a role is granted to
a *person*; an entitlement is bought as part of a *service*. If reseller were a customer
role, someone would eventually have to remember to revoke it when a service lapses. Attached
to the service, capability appears and disappears with the thing that was purchased —
nothing to remember, nothing to leak.

**The earlier point survives:** still one permission system, rendered on two surfaces. What
changes is where the grant comes from — the service, not the customer.

**The prototype already works this way.** It derives services from the customer and renders
the Wi-Fi section only when a MikroTik service exists (Customer C-09 has none and gets the
empty state, asserted by 6 of the 244 checks). That structure was right for a reason that
only became clear now.

---

## 2. Where each limit is actually enforced

Before comparing models: the five options are not five settings in one place. They are
enforced by **different mechanisms, in different layers, with different maturity in our own
build.** This is the foundation of the comparison.

| Limit | Enforced by | Layer | Status in our design |
|---|---|---|---|
| **Per-session speed** | `Mikrotik-Rate-Limit` RADIUS attribute | Per login | **Specified** — docs/30 §363 |
| **Aggregate site bandwidth** | Simple queue on the router | Router config | **Specified** — A8 step 11; docs/32 step 17 verifies `/queue/simple/print` |
| **Data volume** | `radacct` octet accounting | RADIUS | **Proven to accumulate** — docs/36 tests Start → Interim (octets growing) → Stop |
| **Concurrent sessions** | `Simultaneous-Use` (FreeRADIUS), counted from open `radacct` rows | RADIUS | **Not specified anywhere.** Absent from docs/30, 31, 32, 36 |

### 2.1 The finding that matters most

**Concurrency is the only one of the four with no mechanism in our current design.** It is
not configured, not tested, and not in the A8 push order. Choosing a concurrency-based model
is choosing to build something Phase 0 does not currently produce.

That is not an argument against it. It is a cost that belongs in the decision.

### 2.2 And it interacts with C8′

`Simultaneous-Use` counts sessions left open in `radacct`. A session closes when the router
sends `Accounting-Stop` — **over the tunnel.** If the tunnel drops mid-session, the Stop may
never arrive and the row stays open.

**Consequence: after an outage, the concurrent count reads high, and legitimate paying guests
are refused** because the system believes seats are occupied by sessions that ended. Standard
mitigation is an interim-update timeout with a reaper process, but that is real work and a
real failure mode — and it is the *same* tunnel that C8′ is about.

**C11=A or D or E makes C8′ more urgent, not less.** Any model that counts sessions inherits
the tunnel's reliability.

### 2.3 The distinction that makes option B weaker than it looks

*"50 Mbps"* and *"8 Mbps per guest"* are different mechanisms — an aggregate queue versus a
per-login attribute. Nothing connects them.

So under a pure bandwidth entitlement, Riverside can create a plan at 8 Mbps and sell 20 of
them: **160 Mbps of promises against a 50 Mbps pipe.** Nothing refuses it. Everyone simply
gets a slower share.

**Bandwidth entitlement shares out contention. It does not cap what the customer may
promise.** Only a session count does that. This is the crux of C11 and it is easy to miss,
because "they can't exceed 50 Mbps" is true of *throughput* and false of *promises*.

---

## 3. Comparison

**A** concurrent sessions · **B** bandwidth · **C** data volume · **D** B+A · **E** B+A+C

| | **A — Concurrent** | **B — Bandwidth** | **C — Data volume** | **D — Bandwidth + concurrent** | **E — All three** |
|---|---|---|---|---|---|
| **DishNet sells** | N simultaneous users | X Mbps to the site | N GB per month | X Mbps **and** N users | X Mbps, N users, N GB |
| **Customer receives** | A seat count | A pipe | A bucket | A pipe with a seat count | All three |
| **Enforced** | RADIUS refuses login N+1 | Router queue shapes total | RADIUS refuses when pool spent | Queue + login refusal | All three |
| **Bounds retail plans** | Devices/voucher × live vouchers ≤ N | Per-plan speed ≤ X | Plan data ≤ remaining | Both | All |
| **On exhaustion** | **Next guest refused at login** | **Nobody refused — everyone slows** | **Everything stops for everyone** | Refused at the seat cap | Whichever binds first |
| **Who feels it** | One guest, visibly, now | All guests, invisibly | All guests, abruptly | One guest | Varies — harder to explain |
| **Pricing** | Per seat — scales with **revenue** | Per Mbps — familiar ISP unit | Per GB — familiar mobile unit | Two dials | Three dials |
| **Reporting** | Peak concurrency, seat utilisation | Throughput, peak vs committed | GB consumed, burn rate, days left | Both | All — richest, most to explain |
| **Vouchers** | Sold ≠ usable. **Oversold vouchers strand paying guests** | Any number usable, all degraded | Any number, until the pool dies | Seat cap binds | Complex interaction |
| **Multi-site** | Pool or per-site (§5) | **Naturally per-site** — a queue lives on a router | **Naturally pooled** — one account | Mixed: per-site pipe, pooled or per-site seats | Most complex |
| **Commercially easiest** | ✗ *"How many do I need?"* is hard to answer | **✓✓ Every ISP sells Mbps** | **✓✓ Every Ugandan buys GB bundles** | ✓ Two familiar units | ✗ Three dials, hard to quote |
| **Technically hardest** | **✗✗ Not built. Stale sessions. Tunnel-dependent** | **✓✓ Easiest — one queue at provisioning** | ✗ Aggregation across sessions and sites; interim-dependent | ✗ Inherits A's difficulty | ✗✗ Everything at once |

---

## 4. Notes the table cannot hold

**A — Concurrent.** The only model that structurally prevents overselling: the customer
cannot promise more simultaneous access than they bought. But the failure lands on a guest
who has already paid Riverside, at the moment they try to connect — and Riverside must refund
someone standing in front of them. It is the strongest bound and the harshest failure.

**B — Bandwidth.** Easiest to sell, easiest to enforce, weakest bound (§2.3). It never
refuses anyone, which is commercially attractive and operationally dangerous: the only signal
of oversell is guests complaining the Wi-Fi is slow, and the portal says *"Powered by
DishNet"* (C15).

**C — Data volume.** Commercially the most familiar unit in Uganda — everyone buys bundles.
But the exhaustion behaviour is the worst of the three: service ends for **everyone at once**,
possibly mid-stay, including guests who just bought a day pass an hour ago. Needs threshold
alerts and a top-up path, or it will produce refund disputes.

**D — Bandwidth + concurrent.** The combination most hotspot products converge on, because
it matches how the resource actually behaves: a pipe that is shared, and seats that are
finite. Costs A's technical difficulty.

**E — All three.** Most precise, most defensible in a dispute, hardest to quote. A hotel
owner asked to choose Mbps *and* seats *and* GB will not know how to size any of them, and
DishNet will end up choosing for them — at which point two of the three dials are decoration.

---

## 5. Cross-cutting: per-site or pooled?

This applies to every option and is easy to forget.

| | Per-site | Pooled across sites |
|---|---|---|
| Riverside Lobby + Poolside | Each gets its own ceiling | One ceiling shared |
| Customer experience | Rigid — a quiet Poolside cannot lend to a busy Lobby | Flexible |
| Enforcement | Local to each router — simple | Needs central accounting across routers |
| Fits naturally | **B** (queue is physically on a router) | **C** (one data account) |
| A / D / E | **Must be decided explicitly** — neither is natural | |

Note the tension: bandwidth is naturally per-site, data is naturally pooled. **Option E
therefore has to answer this question twice, in opposite directions.**

---

## 6. Riverside Hotel under each model

Same hotel, same two sites, same retail ambition.

**DishNet sells:**

| | A | B | C | D | E |
|---|---|---|---|---|---|
| | 20 concurrent users | 50 Mbps | 500 GB/month | 50 Mbps + 20 users | 50 Mbps + 20 users + 500 GB |

**Riverside creates (identical in all five — this is the point):**

| Retail plan | Duration | Devices | Speed | Price |
|---|---|---|---|---|
| 1 Hour | 60 min | 1 | 5 Mbps | UGX 2,000 |
| 6 Hours | 6 h | 1 | 8 Mbps | UGX 5,000 |
| Lobby Day Pass | 24 h | 2 | 8 Mbps | UGX 8,000 |
| 1 Week | 7 days | 3 | 8 Mbps | UGX 25,000 |

Prices and packaging are Riverside's alone. The system's only question is whether each plan
fits the entitlement.

**What the platform checks at plan creation:**

| | Check |
|---|---|
| **A** | Devices per voucher ≤ 20; live vouchers × devices ≤ 20 |
| **B** | Plan speed ≤ 50 Mbps ✓ — **and nothing else** |
| **C** | Plan data allowance ≤ remaining pool |
| **D** | Speed ≤ 50 **and** seat maths as A |
| **E** | All three |

**Saturday night — 30 guests want online, all holding paid day passes:**

| | What happens |
|---|---|
| **A** | 20 connect. **10 paying guests refused.** Riverside refunds 10 people at reception |
| **B** | **All 30 connect** at roughly 1.6 Mbps each. Nobody refused; video stutters; guests call it bad Wi-Fi |
| **C** | All 30 connect at full speed — **until the 500 GB runs out**, then all 30 stop at once, mid-evening |
| **D** | 20 connect at up to 8 Mbps; 10 refused. Bounded and predictable, same refunds as A |
| **E** | As D, plus the pool can still end the evening for everyone |

**This single row is the clearest statement of the choice.** The models do not differ in
what Riverside may sell. They differ entirely in **who absorbs the failure, and how visibly.**

---

## 7. Three things that should drive the decision

Not a recommendation — the considerations I would want weighed.

1. **Only a seat count bounds what the customer may promise.** B and C bound *consumption*,
   not *promises*. If the concern behind C11 was "stop a customer overselling DishNet
   capacity", B alone does not do it (§2.3).

2. **Every model that counts sessions inherits the tunnel.** A, D and E depend on
   `Simultaneous-Use`, which depends on `Accounting-Stop` arriving over WireGuard. That
   makes C8′ a dependency, not a neighbour, and adds a failure mode where paying guests are
   refused because of stale rows (§2.2).

3. **The easiest to sell is the hardest to govern, and the reverse.** Bandwidth: trivial to
   quote, trivial to enforce, weakest control. Concurrency: strong control, not yet built,
   hard for a hotel owner to size. The decision is largely a choice about **where you want
   the difficulty to sit** — in the sales conversation, or in the platform.

---

## 8. Consequences for what gets built

Whichever is chosen, two things follow:

- **The plan editor must show the entitlement it is bounded by**, or the customer will
  create plans that are silently rejected. The ceiling should be visible while they type,
  not discovered on save.
- **The customer must be able to see their headroom** — seats in use, throughput, GB left —
  otherwise they cannot run the business they have been sold. This is a Customer PWA screen
  that does not exist yet.

---

## 9. What I have not done

I have not chosen a model. A, B, C, D and E are presented with their costs; §7 gives
considerations, not a recommendation.

§2 is evidence from our own documents — the four enforcement mechanisms and their status
were read from docs/30, 31, 32 and 36, not assumed. The claim that concurrency is
unspecified is an absence-of-evidence claim across those four documents; if it is specified
somewhere I did not search, that changes §2.1.

**No production system was contacted. No prototype, schema or architecture was changed.**
