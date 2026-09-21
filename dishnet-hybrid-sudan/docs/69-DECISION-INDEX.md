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
| **2a** | **Cross-customer AAA security floor** | **PROVEN requirement; mechanism NOT CHOSEN** | See §2a below | **docs/68 §2.4** | docs/68 §2.4b, §2.4c |
| **2b** | **Product / site-binding policy** | **OPEN — business decision** | A, B or C. Not selected. C20 evidence: **Q3 closed**, Q1 and Q2 open — docs/68 §2.6 | **docs/68 §2.5** | docs/65 §5, docs/68 §2.6 |
| **3** | **Portal contract** | **CLOSED** | **P2** — generated AAA credentials; the commercial voucher code never enters the AAA credential path | **docs/67** | docs/65 §6, §12.4 |
| **4** | **Front-desk activation** | **OPEN — business decision** | Whether an operator may activate on a guest's behalf | **docs/67 §7** | docs/64 §2 Q5 |
| **5** | **Rate limits and timing** | **OPEN** | Numbers only; the shape is settled | **docs/65 §9**, docs/67 §10 | docs/65 §9.2, §12.4 |
| **6** | **Retention** | **OPEN** | Two separate questions: the control plane's attempt store, and `radpostauth` | **docs/66 §7** | docs/65 §12.4 |
| **7** | **AAA publication authority and reconciliation** | **CLOSED** | Dedicated **AAA Publisher**, with the documented privilege boundary and reconciliation principles | **docs/66 §2** | docs/65 §12, docs/68 §2.4c |

---

## 2a — the distinction that must not collapse

Four separate statements. docs/68 §2.4c holds the full version.

| | |
|---|---|
| **Security requirement** | **PROVEN.** A credential must not authenticate outside its customer's estate, and FreeRADIUS can enforce that from a server-derived source address |
| **Production mechanism** | **NOT CHOSEN.** No mechanism has been selected to build |
| **Huntgroups** | a **measured viable mechanism**, not an architectural selection. They work (E7, E8) and cost a full FreeRADIUS restart per mapping change (E9) |
| **C-b, the SQL dynamic-set candidate** | **MEASURED: `WORKS`** (docs/68 §2.4f) — and **not chosen**. The set can live in the SQL authorize path, so a mapping change is a row rather than a restart. It is a second proven candidate, not a selection |
| **C-a, C-c, C-d** | still **candidates**, none measured, none selected |

So there are now **two** proven mechanisms and still **no chosen one**.

Evidence items **E1–E9** are enumerated in docs/68 §2.4c and citable by number.
The investigation and its constraints are docs/68 §2.4e; C-b's result is §2.4f.

---

## Open business-model observation — not reconciled

**The ISP/operator hierarchy.** C20 evidence records that a DishNet customer may
itself be an ISP or operator managing its own downstream customers:
**DishNet → ISP/operator customer → sites → downstream users**, with each site
generating its own voucher stock. This layer is **new relative to the frozen
documents** and has deliberately **not** been reconciled into them. It is an open
business-model observation, not an architecture change. (`docs/68` §2.6)

---

## Implementation dependencies that must not be forgotten

| | Dependency | Where |
|---|---|---|
| **D-1** | **Huntgroup / NAS-set maintenance is a second state path** — server-side configuration, not RADIUS database state, and a change needs a full restart. The publisher must not receive arbitrary filesystem or configuration access | docs/68 §2.4d, pointer at docs/66 §9a |
| **D-2** | **A NAS identifier must exist in the control plane** and map to a device. It does not today | docs/68 §2.7 |
| **D-3** | **Invariant C1** — generated AAA credentials, independent of the voucher in every respect | docs/68 Part 1 |
| **D-4** | The **publication state machine** — `activating`, `activation_failed`, `revoking` — must not be collapsed into a generic HTTP 500 | docs/66 §4, docs/67 §4.2 |
| **D-5** | **Publication must be idempotent**; the AAA schema will not enforce it | docs/66 §2.6, docs/65 §12.3 |

---

## Deliberately deferred

| | Item | State | Requirements |
|---|---|---|---|
| **1** | **`s1_s2_probe.php` repair** | deferred since `bf5310b` | A separate **test-maintenance change**. Four requirements recorded in **docs/65 §15** |
| **2** | **C1 guard tests** | not yet written | Required **at** the F6 implementation gate, not after it. Specified in **docs/68 Part 1** |

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
| **docs/68** | credential invariant C1; Decision 2a evidence and 2b's open state |
| **docs/69** | this index |

Reproducible artifacts live in `dishnet-mikrotik-control-plane/tools/audit/`:
`proto_f6*` (the actor and lifecycle prototypes), `f6_radius_restriction.sh`
with `f6_rad_client.py` (the isolated FreeRADIUS experiment), and
`f6_cb_sql_dynamic.sh` (candidate C-b). All disposable; none touches Phase 0.

---

## Production state

Nothing production-facing has been changed by any of the above: no code, schema,
privilege, FreeRADIUS configuration or deployment, on either installation. F6
itself remains unfixed — `dnb_app` still holds `EXECUTE` on `mt_voucher_redeem`,
as docs/63 recorded it.
