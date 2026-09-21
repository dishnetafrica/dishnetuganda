# 69 — Decision index

**Navigational only.** No analysis, no recommendations, no reconciliations, no
decisions. Every statement here points at a document that holds the
authoritative version. Where this index and a decision document disagree, the
decision document is right.

Covers the F6 voucher work, docs/63–68, and the architecture freeze it sits
under.

---

## The decision set

| # | Decision | State | What was decided | Authoritative record | Evidence |
|---|---|---|---|---|---|
| — | **F1–F13 architecture freeze** | **FROZEN** | 13 items, unfrozen only by explicit amendment to docs/53 §5 | **docs/53** | — |
| **1** | **AAA publication lifecycle** | **CLOSED** | **Model B** — the AAA credential is published at redemption/activation, not at voucher issue | **docs/66 §1** | docs/65 §3 (both models measured across all three layers), §4 |
| **2a** | **Cross-customer AAA security floor — and the mechanism enforcing it** | **CLOSED** | **C-b — site-keyed dynamic SQL source-address restriction**, using `dnb_cred_site`, `dnb_site_nas` and the `EXISTS` predicate against the authorized NAS/source-address set. **Huntgroups retired as a production candidate** | **docs/70 §8** (requirement: docs/68 §2.4) | docs/70 §2, §8.1; docs/68 §2.4b–g |
| **2b** | **Product / site-binding policy** | **CLOSED** | **A / SITE-BOUND** — a voucher is bound to its issuing site and valid only against the NAS set authorized for that site. Does **not** imply one router per site | **docs/68 §2.5** | docs/68 §2.5a, §2.6, §2.6a–b |
| **3** | **Portal contract** | **CLOSED** | **P2** — generated AAA credentials; the commercial voucher code never enters the AAA credential path | **docs/67** | docs/65 §6, §12.4 |
| **4** | **Front-desk activation** | **OPEN — business decision** | Whether an operator may activate on a guest's behalf | **docs/67 §7** | docs/64 §2 Q5 |
| **5** | **Rate limits and timing** | **OPEN** | Numbers only; the shape is settled | **docs/65 §9**, docs/67 §10 | docs/65 §9.2, §12.4 |
| **6** | **Retention** | **OPEN** | Two separate questions: the control plane's attempt store, and `radpostauth` | **docs/66 §7** | docs/65 §12.4 |
| **7** | **AAA publication authority and reconciliation** | **CLOSED** | Dedicated **AAA Publisher**, with the documented privilege boundary and reconciliation principles | **docs/66 §2** | docs/65 §12, docs/68 §2.4c |

---

## 2a — the distinctions that must not collapse

**The mechanism is now chosen.** docs/70 §8 holds the decision; docs/68 §2.4
holds the requirement it enforces. Three distinctions still matter.

| | |
|---|---|
| **Security requirement** | **PROVEN.** A credential must not authenticate outside its authorized NAS set, and FreeRADIUS can enforce that from a server-derived source address |
| **Production mechanism** | **CHOSEN: C-b**, site-keyed (`dnb_cred_site` + `dnb_site_nas` + the `EXISTS` predicate). docs/70 §8 |
| **Chosen ≠ built** | **Nothing is implemented.** The two tables do not exist, the production authorize query is still username-only, and the query change is a separately authorized step. docs/70 §8.2–8.3 |
| **Measured ≠ measured in production** | Every measurement behind the decision ran on a **disposable** instance (docs/70 §2). **Production has never been touched** and the mechanism has never run there. docs/70 §5.2 |
| **Huntgroups** | **RETIRED as a production candidate.** They were measured viable (E7, E8) but cost a full FreeRADIUS restart per mapping change in **both** directions — E9 (granting) and **E10** (removing, so stale authorization persists until that restart). E10 is why they were retired. They remain recorded for evidence value only; **do not build on them** |
| **C-a, C-c, C-d** | never measured, and now **unnecessary** — a mechanism has been chosen |

Evidence items **E1–E9** are enumerated in docs/68 §2.4c; **E10** and **S1–S9**
are in docs/70 §2, summarised in docs/70 §8.1. All are citable by number.

## Open business-model observation — not reconciled

**The ISP/operator hierarchy.** It informed Decision 2b's rationale — a customer
may serve unrelated downstream businesses, so customer-level commercial
ownership does not become customer-wide voucher scope (`docs/68` §2.5). It
remains a business-model input and is **not** a tenant layer in the
architecture. C20 evidence records that a DishNet customer may itself be an ISP
or operator managing its own downstream customers:
**DishNet → ISP/operator customer → sites → downstream users**, with each site
generating its own voucher stock. This layer is **new relative to the frozen
documents** and has deliberately **not** been reconciled into them. It is an open
business-model observation, not an architecture change. (`docs/68` §2.6)

---

## Implementation dependencies that must not be forgotten

| | Dependency | Where |
|---|---|---|
| **D-1** | **The site→NAS mapping is a second state path.** Under the chosen mechanism it is `dnb_site_nas` in the `radius` database, written on the **device-provisioning** lifecycle — *not* the publication lifecycle. It carries **site isolation and cross-customer isolation at once**, so the AAA Publisher must **not** be able to write it | docs/70 §5.1, §8.4; docs/68 §2.4d; pointer at docs/66 §9a |
| **D-6** | **The Decision 7 extension — a narrowly scoped provisioning writer** for `dnb_site_nas`. Designed in docs/71; **awaiting approval and blocked**. **N10/P-1** is measured exploitable — and **from the request role, not just `dnb_admin`** (docs/73 §1.1) — with a recommended remediation in **docs/73 §4** awaiting approval; **Q2** (may one router serve two sites?) is **NOT ESTABLISHED** and blocks the `dnb_site_nas` key | **docs/75**, docs/74, docs/73, docs/72, docs/71 |
| **D-2** | **A NAS identifier must exist in the control plane** and map to a device. It does not today | docs/68 §2.7 |
| **D-3** | **Invariant C1** — generated AAA credentials, independent of the voucher in every respect | docs/68 Part 1 |
| **D-4** | The **publication state machine** — `activating`, `activation_failed`, `revoking` — must not be collapsed into a generic HTTP 500 | docs/66 §4, docs/67 §4.2 |
| **D-5** | **Publication must be idempotent**; the AAA schema will not enforce it | docs/66 §2.6, docs/65 §12.3 |

---

## Deliberately deferred

| | Item | State | Requirements |
|---|---|---|---|
| **1** | **`s1_s2_probe.php` repair** | deferred since `bf5310b` | A separate **test-maintenance change**. Four requirements recorded in **docs/65 §15** |
| **2** | **C1 guard tests** | not yet written | Required **at** the F6 implementation gate, not after it. Specified in **docs/68 Part 1**, and must now also assert that **no client-asserted value reaches the authorize query** (docs/70 §5.3, §8.2 item 4) |

---

## Document map

| Document | What it is |
|---|---|
| **docs/53** | the architecture freeze, F1–F13 |
| **docs/63** | F6 voucher-redemption audit — the original finding |
| **docs/64** | F6 actor model and disposable prototype |
| **docs/65** | lifecycle evidence and the open-decision set. Rows 1, 3 and 7 struck through with pointers; §12 holds the AAA-boundary evidence |
| **docs/66** | **Decision record: Decisions 1 and 7** |
| **docs/67** | **Decision record: Decision 3** (amended once by docs/68 §3) |
| **docs/68** | credential invariant C1; **Decision 2b (closed)**; Decision 2a's requirement and evidence (§2.4a–g) |
| **docs/69** | this index |
| **docs/70** | **Decision record: Decision 2a (closed)** — the sixteen-requirement comparison, E10 and S1–S9, the reasoning, and the accepted consequences that are **not yet authorized to build**. §2 carries a correction to S2's reading |
| **docs/71** | **Decision 7 extension — design and evidence gate (awaiting approval).** The provisioning writer's security boundary, threat cases T1–T10, the privilege model, invariants N1–N9, findings **P-1/P-2/P-3**, and questions Q1–Q6. Builds nothing |
| **docs/72** | **Evidence gate:** P-1 **measured** (`DOES NOT WORK / exploitable`), Q2 **NOT ESTABLISHED**, both cardinality branches, and invariant **N10**. Chooses no schema |
| **docs/73** | **N10 remediation design (awaiting approval):** ten measurements M1–M10, the three candidates across thirteen dimensions, the eight-state matrix, and the recommendation — composite FK `MATCH SIMPLE` + a one-table CHECK + a validating backfill. Q2 held open. Implements nothing |
| **docs/74** | **N10 backfill data state:** fixture census (0 violations, 0 partial nulls), suite impact (718 green **with** the constraints), the five backfill classes, and the scope finding that `mt_plans`, `mt_voucher_batches` and **`mt_vouchers`** carry the same untied pair. **Production state NOT ESTABLISHED** |
| **docs/75** | **Customer/site ownership scope audit:** which tables genuinely need the invariant. `mt_devices`, `mt_vouchers`, `mt_voucher_batches` **yes** (one chain); `mt_plans` **no** (commercial scoping, read by nothing). All three measured able to hold a cross-customer pair. Also: `mt_vouchers.site_id` is NULLABLE, which Decision 2b cannot describe. Changes nothing |

Reproducible artifacts live in `dishnet-mikrotik-control-plane/tools/audit/`:
`proto_f6*` (the actor and lifecycle prototypes), `f6_radius_restriction.sh`
with `f6_rad_client.py` (the isolated FreeRADIUS experiment), and
`f6_cb_sql_dynamic.sh` (candidate C-b), `f6_site_bound_reassign.sh` (the
site-keyed mapping, reassignment, and the fail-closed cases S1–S9) and
`f6_huntgroup_reassign.sh` (E10). All disposable; none touches Phase 0.

---

## Production state

Nothing production-facing has been changed by any of the above: no code, schema,
privilege, FreeRADIUS configuration or deployment, on either installation. F6
itself remains unfixed — `dnb_app` still holds `EXECUTE` on `mt_voucher_redeem`,
as docs/63 recorded it.

**This is true after Decision 2a closed.** Closing 2a chose a mechanism; it built
nothing. `dnb_cred_site` and `dnb_site_nas` do not exist, the production
authorize query is still username-only, and no new role or privilege has been
created.

---

## Where the work stands

| | |
|---|---|
| **Closed** | Decisions **1**, **2a**, **2b**, **3**, **7**. F1–F13 frozen |
| **Open** | Decisions **4** (front-desk activation), **5** (rate-limit and timing numbers), **6** (retention — control plane and `radpostauth` separately) |
| **The next gate** | **Approve the integrity scope** (docs/75 §5: `mt_devices` + `mt_vouchers` + `mt_voucher_batches` together, `mt_plans` separate) and answer the **2b completeness question** — may a voucher be issued with no site? Then the production census (docs/74 §9), then the migration. **Q2** still blocks the `dnb_site_nas` key. **Not F6 implementation** |
| **F6** | **NOT AUTHORIZED.** It is not the next step, and closing 2a did not bring it closer to being authorized |
| **Unanswered factual input** | whether a site may have several MikroTik HotSpot routers (docs/68 §2.6b). It did **not** block Decision 2a and is **not** settled by it |
