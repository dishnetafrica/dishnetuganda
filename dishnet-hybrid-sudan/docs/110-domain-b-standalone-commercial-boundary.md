# 110 — Domain B as a standalone platform, and the commercial boundary

**Design only. No schema change, no migration, no production access, no
application code, no fixture change.**

> **Numbering note.** This is `docs/110`, not `docs/109`. `docs/109` is already
> the Q7 evidence report (commit `4121de5`), referenced from
> `docs/69-DECISION-INDEX.md` and `CLAUDE.md`; renaming it would break those.

---

## 1. The correction this document makes

`docs/105` §9.2 / `docs/106` §9 proposed that **plan creation and voucher issuance require the
uCRM customer link**, on the grounds that they are revenue-bearing.

> **That proposal is WITHDRAWN.** A stored uCRM link is **not** a prerequisite
> for HotSpot network operation. Nothing in the chain
> `operator → site → router → plan → voucher batch → voucher → redemption → AAA
> → session → accounting` requires a uCRM customer or a uCRM service.

The reasoning that produced it was that revenue-bearing operations should carry
commercial identity. The error was treating **commercial representation** and
**technical capability** as the same requirement. A voucher sale is revenue to
the *operator*, and only becomes DishNet revenue where DishNet has chosen to
bill that operator commercially — which is a separate relationship.

---

## 2. The starting point is measured, not designed

**Domain B is already standalone with respect to uCRM.** This is not a boundary
to build; it is one to preserve.

| Measurement | Result |
|---|---|
| non-column uCRM references in `src/` | **zero** — no client, no adapter, no API call |
| every `ucrm_client_id` occurrence | the schema (`integer UNIQUE`, **nullable**, *"C16 is open"*), two Admin read projections, one serializer constant and its comment — and **writes only in `tests/bootstrap.php`** |
| outbound HTTP clients in Domain B | **exactly one**: `Delivery/RouterOs/RestClient.php` — to a **router**, not to uCRM. And it is inert (`NullDelivery` is bound; F6-B unauthorized) |
| customer-plane exposure of the link | **withheld** — `Projection.php`: *"`ucrm_client_id` is internal billing linkage; status is not the customer's business"* |

> **Domain B makes no outbound call to uCRM, and cannot: there is no code that
> would.** The column is a nullable placeholder that only a test fixture has
> ever written. That is I-1 restated — and read as a *boundary* rather than a
> defect, it is exactly the property this document freezes.

**Consequence for the schema: nothing to do.** `ucrm_client_id` is *already*
nullable. The standalone boundary requires **no migration**. The only change
that would break it is **U-2** (`NOT NULL`), which remains gated on **E-2**.

---

## 3. Three identities

| | Identity | What it is | Requires uCRM? |
|---|---|---|---|
| **1** | **Domain-B operator / customer** | the **authorization boundary** — what RLS keys on (`customer_id = mt_current_customer()`); owns sites, routers, plans, vouchers, sessions | **No** |
| **2** | **uCRM commercial customer / service** | DishNet's CRM, billing, invoicing and support identity for an operator DishNet bills | by definition, where such a relationship exists |
| **3** | **Guest / voucher user** | a transient HotSpot identity: a code, an AAA credential, a session | **Never** |

### 3.1 The guest is not a customer of anybody's CRM

A guest who buys an hour of Wi-Fi at a hotel is a customer **of the operator**,
not of DishNet. Measured constraints that already enforce this:

- **no `mt_customers` row, no principal, no login** — a guest has no tenant;
- **no actor kind.** `actor_kind` is CHECK-constrained to
  `principal | staff | system`, and `docs/89` settled that the **attempt store**
  gives a guest attribution — source, `nas_claimed`, code prefix/hash, outcome —
  **without pretending it is a principal or staff**. **No `guest` actor kind is
  to be added**;
- **no CRM record of any kind.** *"AI should never receive information merely
  because it exists in your database"* applies with more force to a person who
  never entered into a relationship with DishNet at all.

> **A guest must never become a uCRM record because they bought Wi-Fi.** Not a
> client, not a lead, not a contact.

### 3.2 Identity 1 is not a projection of identity 2

`mt_customers` is the Domain-B **authorization boundary**. It carries a uCRM
relationship when one exists. **Its existence is not evidence that uCRM is
required**, and it is not a cache of uCRM (`docs/101`).

---

## 4. The independence table, with evidence

| Object / operation | uCRM required? | Basis |
|---|---|---|
| Domain-B customer / operator creation | **No** | `mt_customer_create(p_name, p_created_by)` cannot set a link; minimum valid customer is a name and an actor |
| MikroTik registration / staging / shipping | **No** | `customer_id` nullable — *inventory is not ownership* (`docs/102`) |
| Device assignment | **No** | takes device, customer, site — no uCRM parameter exists |
| Site creation | **No** | derives its customer from the service (`docs/106` §8) |
| HotSpot plan creation | **No** | `PlanRepository` writes under RLS; no uCRM reference |
| Voucher batch | **No** | §1 — **corrected** |
| Voucher issuance | **No** | §1 — **corrected** |
| Voucher redemption | **No — FORBIDDEN** | `docs/89`/`102`: tenant data must not be required for the anti-enumeration check, and a remote round-trip would break response uniformity |
| RADIUS authentication | **No — FORBIDDEN** | a separate PostgreSQL instance; the authorize query takes a username and (under 2a) a site-keyed NAS predicate |
| Session accounting | **No — FORBIDDEN** | `dnb_radius` holds one EXECUTE and **no table privileges**; a NAS packet must not trigger a CRM lookup |
| Audit | **No — FORBIDDEN as a gate** | or the least-established records become the least recorded (`docs/102`) |
| **uCRM commercial customer** | — | **when DishNet chooses to bill that operator through uCRM** |
| **uCRM service link** | — | **only where such a commercial relationship exists** (U-5, open) |

**"Optional" does not mean "never used."** The uCRM bridge (`docs/98` R-1)
remains part of the architecture for every operator DishNet manages commercially.
What is rejected is uCRM as a **technical dependency of HotSpot operation**.

---

## 5. Standalone onboarding — both flows

```
CUSTOMER-FIRST                          EQUIPMENT-FIRST

mt_customer_create                      mt_device_register
      │                                        │
      ▼                                        ▼
mt_principal_create                     mt_device_stage
      │                                        │
      ▼                                        ▼
mt_service_create                       mt_device_ship
      │                                        │   ◀── no customer, no uCRM
      ▼                                        │
mt_site_create                          mt_customer_create
      │                                        │
      ▼                                        ▼
mt_device_register → stage → ship       mt_principal_create → mt_service_create
      │                                        │      → mt_site_create
      └────────────────┬───────────────────────┘
                       ▼
             ╔═══════════════════╗
             ║ mt_device_assign  ║   ← the only convergence point
             ╚═══════════════════╝
                       │
                       ▼
                 provisioning
                       │
                       ▼
            plans → voucher batch → vouchers
                       │
                       ▼
          guest redemption → AAA → session → accounting

            ── no uCRM anywhere in this diagram ──
```

Both flows complete end to end with **no uCRM record in existence**. The
commercial and network chains stay independent, identical in both journeys, and
converge only at `mt_device_assign` (`docs/107` §7). **Convergence is not a
gate.**

---

## 6. Where uCRM integration enters

> **At exactly one point, and it is beside the lifecycle rather than inside it:**
> **a link recorded against an existing Domain-B operator**, when DishNet decides
> to represent that operator commercially in uCRM.

```
        Domain-B operator ──────────────────────────────▶ continues regardless
                  │
                  │  ← the ONLY integration point:
                  │    an audited link, written once, with provenance
                  ▼
        uCRM client (+ service, where one applies)
                  │
                  ▼
        billing · invoicing · dunning · support
```

Properties, all previously settled and unchanged by this document:

- **written once, with provenance** — `linked_by`, `linked_at`, and an audited
  unlink/relink carrying previous and new relationship plus a reason
  (`docs/101`);
- **never inferred per request**, and **never from a phone number** —
  `LeadMatcher` warns loose matching *"can identify the WRONG customer and
  disclose their balance"*;
- **`dnb_adminwrite` only** — never `dnb_app`, never `dnb_portal`;
- **coherence**: the uCRM service's `clientId` must equal the client linked to
  that service's customer — *"the most dangerous operation in the bridge"*;
- the bridge plugin **must never** connect to Domain-B PostgreSQL, hold a
  Domain-B role credential, write a Domain-B table, or call a provisioning
  function (`docs/98` R-1).

---

## 7. If uCRM is unavailable

| Continues working | Why |
|---|---|
| **every operation in §4 and §5** | Domain B makes no outbound call to uCRM — measured §2 |
| guest redemption, AAA publication, RADIUS auth, session accounting | uCRM is **forbidden** there, so its absence is not a degradation |
| the Admin **read** boundary — thirteen projections | reads Domain-B PostgreSQL only |
| voucher sale by the operator | no CRM involvement at any step |

| Degrades or stops | Why |
|---|---|
| billing, invoicing, dunning | they exist only in uCRM |
| support / tickets | uCRM-side, and `docs/100` found it may not be API-reachable at all |
| creating **new** links, and reading commercial data | the bridge is the only path |
| **staff authentication into the Admin panel** | **see below** |

> **One consequence to accept knowingly.** W-4 is open, so there is no staff
> identity today. The *proposed* solution (`docs/98`, U-7: S-1 proxy or S-2
> signed assertion) authenticates staff **through uCRM**. Under either, **a uCRM
> outage removes Admin write access** — while leaving the network plane and every
> guest transaction running. That is a defensible split, but it should be chosen
> deliberately rather than discovered during an outage.

---

## 8. What genuinely requires uCRM

Only what uCRM actually owns:

1. **Billing** — invoices, payments, dunning. **Zero matches in
   `src/Api/Routes.php`**; it can only come from uCRM, and no adapter exists.
   **Do not fabricate the screen.**
2. **Support** — and it is weaker than "not built": `docs/100` found **no
   `tickets` endpoint among the uCRM paths called**, so it is *"not known to be
   possible"*.
3. **The commercial customer record itself**, where DishNet bills the operator.
4. **Staff identity**, under the proposed bridge only (§7).

Nothing in that list is on the HotSpot operating path.

---

## 9. uCRM is NOT in the real-time path — stated explicitly

```
guest code ──▶ portal redemption ──▶ AAA publication ──▶ RADIUS auth ──▶ session ──▶ accounting
   │                                                                                     │
   └────────────────────── uCRM appears NOWHERE on this line ────────────────────────────┘
```

This is **not a performance preference**. It is already a security requirement:

- **anti-enumeration** — tenant/customer data must not be *required* to perform
  the check, because an unknown code resolves no customer, *"exactly the case
  enumeration produces"* (`docs/89`);
- **response uniformity is not tradeable**, and a remote round-trip makes
  timing depend on a third party;
- **availability** — a CRM outage must never stop a paying guest connecting;
- **privilege** — `dnb_portal` and `dnb_radius` hold **no table privileges** and
  one EXECUTE each. Neither could reach a CRM adapter even if one existed.

> **Any future change that puts a uCRM call on this line is a regression, not a
> feature.**

---

## 10. Two prohibitions

- **No placeholder uCRM customer.** Never create a uCRM client to satisfy a
  Domain-B field, and never create a Domain-B customer to satisfy a uCRM one.
  `docs/102` closed the mirror of this (L-1): *inventory is not ownership*, and
  the same logic forbids inventing commercial identity to fill a column.
- **No guest CRM records.** §3.1. Not a client, not a lead, not a contact.

---

## 11. U-1 redefined; U-5 deferred

**U-1 — OPEN, and restated:**

> **When, and under what commercial circumstances, does a Domain-B operator get
> linked to uCRM?**

That is a **commercial / integration decision**, not a technical dependency of
HotSpot. The previous framing — *which operation first requires the link* —
presumed the answer this document withdraws. **Q7 must not be converted into a
technical prerequisite for Domain-B onboarding**; an unanswered question is a
reason to leave the boundary open, not to tighten it.

**U-5 — OPEN and deferred.** The uCRM service-link model is not to be designed
further until it is actually needed and its shape defined. `docs/109` §8.1 noted
its premise may be empty: no uCRM HotSpot service plan is evidenced anywhere.

**U-2 — unchanged, gated on E-2.** It is the one decision that would break this
boundary, and it stays shut.

---

## 12. Sequence

```
1. FREEZE THE BOUNDARY   ← this document
2. define the uCRM bridge  (R-1: a thin plugin; Domain B stays separate)
3. design the columns and constraints the bridge needs
4. implement
```

> **Nothing in `mt_customers`, `mt_services` or the onboarding spine changes at
> step 1.** Designing columns before the bridge is defined is what produced the
> withdrawn §9.2 proposal.

---

## 13. Open decisions

| Item | Status |
|---|---|
| **The standalone boundary** | **frozen by this document** — no schema change required; `ucrm_client_id` is already nullable |
| **`docs/105` §9.2 / `docs/106` §9** | **WITHDRAWN** — no uCRM link for plan or voucher operations |
| **Q7** | **OPEN** — one uCRM lookup (`docs/109` §5) |
| **U-1** | **OPEN**, restated as a commercial question |
| **U-5** | **OPEN, deferred** until the bridge needs it |
| **U-2** | **OPEN** — **E-2**; the one decision that would break the boundary |
| **O-1** | closed as a design; census then migration |
| **P-B · P-C · S-A** | **CLOSED** |
| **I-A** | designed; a non-tenant store, approval needed |
| **`session.disconnect` replay** | **blocker before F6-B** |
| **C10 · W-4 · E-2** | **OPEN** |

---

**Stopping here. Suite unchanged: 1,599 assertions, 27 suites. No schema change,
no migration, no application code, no fixture change, no production access. One
proposal withdrawn; the boundary frozen; U-1 and U-5 left open.**
