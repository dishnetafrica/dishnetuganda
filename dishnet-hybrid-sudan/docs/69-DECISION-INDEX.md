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
| **3a** | **Voucher issuance requires a site** | **CLOSED** | Under 2b a voucher may **not** be issued without an issuing site — Option A, reject at creation. `mt_vouchers.site_id` becomes `NOT NULL` | **docs/77 §1** | docs/76 Part A |
| **3b** | **Batch site invariant** — may a voucher batch exist with no site? | **OPEN SCHEMA DECISION** | `mt_voucher_batches.site_id` is **NULLABLE** today. B1 leave nullable / B2 make NOT NULL. **Not a pending consequence of 3a** — it awaits production evidence and explicit approval, and is **not** carried in the migration plan | **docs/78 §4.2** | docs/77 §2 |
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
| **D-6** | **The Decision 7 extension — a narrowly scoped provisioning writer** for `dnb_site_nas`. Designed in docs/71; **awaiting approval and blocked**. **N10/P-1** is measured exploitable — and **from the request role, not just `dnb_admin`** (docs/73 §1.1) — with a recommended remediation in **docs/73 §4** awaiting approval; **Q2** (may one router serve two sites?) is **NOT ESTABLISHED** and blocks the `dnb_site_nas` key | **docs/79**, docs/78, docs/77, docs/76, docs/75, docs/74, docs/73, docs/72, docs/71 |
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
| **docs/76** | **The site-less voucher + the proposed invariant set.** Measured: *every* voucher the suite creates is site-less (30/30), and such a voucher is a sellable object that can never work, indistinguishable from an invalid code. Options A/B/C for the domain decision, the three-table invariant set with NULL semantics, and six migration prerequisites. Decides nothing |
| **docs/77** | **Decision record (voucher site-less issuance CLOSED) + the operator census protocol.** Corrects the scope note: `mt_voucher_batches.site_id` is **NULLABLE**, not NOT NULL. Carries `tools/audit/production_census.sql` — read-only, self-tested, with a blinding guard. No migration |
| **docs/78** | **Census attempted and NOT obtained** — no SSH client, no DSN, egress 403, and the Phase 0 database is loopback-only, so it is unreachable **in principle** from here. Carries the operator runbook, the conditional migration/backfill plan, and **Decision B framed but not taken** |
| **docs/79** | **Operator handoff** — the copy-paste procedure for running the census: command, connection without exposing credentials, role requirements, how to confirm the authoritative database, what to return, and the read-only warning |
| **docs/80** | **F6-A application build plan** — the survey, the two blocking decisions, architecture changes A1–A7, the adapter boundary (simulators may never masquerade as a router), build order P1–P7, and the hardware register H1–H7. **F6-B stays gated** behind `DN_ALLOW_REAL_BINDINGS=yes-f6b-authorized` |
| **docs/81** | **Decision records 8 and 9** — staff identity is a **separate** boundary from `mt_principals`, and **Reseller is not a tenant** or tenant-like security scope |
| **docs/82** | **Customer PWA wiring** — the screens bound to the real API, the three missing contracts G1–G3 named rather than invented, and the correction that the API returns **named keys**, not `{items:[]}` |
| **docs/83** | **Admin cross-customer read boundary — design** — the read surface R1–R12, the table/column map, six options weighed, and the finding that `mt_devices_samplable()` already implements the recommended pattern. Decisions **D-1 accepted, D-2 taken** (no `payload`, `last_error` or `detail` through the general projection) |
| **docs/84** | **Admin write-boundary audit** — twenty-four state-changing operations across fourteen dimensions, findings **F-1…F-6**, the audit and intent requirements, the hardware-dependent operations, and the proposed boundary with its binding order. Open decisions **W-1…W-6**. Changed no code |
| **docs/85** | **Admin write remediation — W-1/W-2/W-3 implemented** (migration 020, development/test schema only). Audit written **inside** each of the seven functions by a narrow `dnb_def_audit` writer no HTTP role may call; the customer/site invariant as a **constraint** so it binds the direct-UPDATE path; and `dnb_adminwrite` with zero table DML. 109 new assertions. **No Admin write route is bound.** New findings **F-7** (`mt_voucher_redeem` unaudited) and **F-8** (six caller-written audit sites). **No backfill; production untouched** |
| **docs/86** | **F-7 / F-8 evidence audit** — the redemption path traced end to end and the six customer-API audit sites inspected. Measured: **redemption is wired to nothing** (`VoucherService::redeem()` has no caller), the AAA registry row is written **at issue**, and `radius_username` is a **reversible transform of the voucher code** — contradicting Decisions 1 and 3. The customer audit sites *are* transactional; the defect is **skippability**, measured. Four mutating routes audit nothing. Actor taxonomy is already fixed by the table's own CHECK (`principal | staff | system`). Questions **Q-F7-1…7** and findings **F-8.1…5**. Changed nothing |
| **docs/87** | **F-7 remediation design (design only)** — the current non-conforming flow, the target Model B + P2 + Decision 7 flow, the full gap list across control plane / `radius` database / application, the actor model (**the existing `principal\|staff\|system` taxonomy suffices; `guest` rejected**), the F-7 audit design, a **separate** F-8 stream, and a nineteen-item execution test plan. Records the rule that **`dnb_cred_site` is written from `voucher.site_id`, never from the guest-asserted NAS**. Seven decisions **P-1…P-7** needed before implementation. Builds nothing |
| **docs/88** | **Decision 5 packet + Decision 4 evidence.** Records **D-1a and P-1 as CLOSED**. Finds that **Decision 5 has a refuted premise**: `docs/65` §9.3 made per-NAS primary, `docs/68` §2.2 refuted the claim it rested on, `docs/65` §9.2 says per-code does not bound enumeration and §9.3 says source IP is not primary — so no dimension is both trustworthy and effective. Four candidate resolutions R-a…R-d; **R-b depends on unverified MikroTik hardware**. Eight-parameter table with documented constraint, proposal, and both reasons; row 1 labelled **an engineering guess** because publication latency has never been measured. Decision 4: `docs/64` Q5's recommendation is **no**, the tenant-crossing danger is **measured**, and it does not block F-7. Also corrects `docs/87` §E — the **attempt store** is required and is where Decision 5's counters live |
| **docs/89** | **Decision record: attempt store, site authority, and the Decision 5 gate.** **CLOSED:** the separate **non-tenant attempt store** (AS-1…AS-6 — never the raw code, `code_prefix` + `code_hash`, outcome classified, counters live here, not `mt_audit_log`, and **no tenant data may be required for the anti-enumeration check**, because an unknown code resolves no customer); and **site authority** — `voucher.site_id` → authorized `dnb_site_nas` → decision, with invariant **SI-1: changing `nas_claimed` alone must never change the site a voucher is authorized at**. **`guest` is not added to `actor_kind`** — the attempt store is the second of the two options `docs/64` §10 offered, and no actor kind is **not** no attribution. **Decision 5 REMAINS OPEN** behind an explicit gate; its eight parameters are restated with **evidence still required**, and read **UNMEASURED / OPEN** wherever a number would be invented |
| **docs/90** | **The MikroTik plugin boundary and the two-plane Admin Panel (built, dev/test).** The control plane becomes an **installable unit**: a manifest whose declared surface is asserted equal to the served surface, a lifecycle that verifies its own work, and **the API entry point that did not previously exist** — `AdminRoutes` was reachable only from tests. The panel moves out of `public/admin/` to `panel/` as a separate consumer holding no database access. Admin UI restructured into **Network plane / Commercial plane**, with Router Detail, a provisioning ladder, Network health and Diagnostics. **Measured: there is no source for WAN link, WireGuard, RADIUS or HotSpot status, and `mt_devices.last_seen_at` is never written** — so all five render "no signal" with reasons, never green. All four router actions render inert with specific reasons. Seven POST paths exist and each answers 501. 1,349 assertions |
| **docs/91** | **MikroTik Admin prototype v0.1 — a coherent simulated estate (built, dev/test).** Diagnoses the list/detail contradiction: there was **no simulator dataset**, the panel read `dnb_test` which the suite drops every run, so it displayed fixture debris — and the two screenshots were never one state. The simulator now has **its own database**, builds through the **real write paths** (so the audit trail exists because the acts happened), and marks every identifier `SIM-`. Router Detail gains IDENTITY / CONNECTIVITY / PROVISIONING / OPERATIONS / ACTIONS with names resolved instead of truncated uuids. **Three further gaps found:** sessions are **not attributable to a router** (`mt_session_account` never sets `device_id`), uplink is **measured but not Admin-readable** (D-4 open), and `wan_interface_set_at` is unexposed. The suite caught the simulator breaking **three architectural rules** (inspector in `src/`, entitlement write, octets without gigawords). 1,446 assertions |
| **docs/92** | **v0.2 inventory — HotSpot, Vouchers, Sessions (inventory only, nothing built).** Read from the running schema, not from memory. Most v0.2 business logic **already exists**; what is missing is read exposure. **Three genuine blockers:** there is **no `mt_admin_services()`**, so HotSpot service state has no Admin read path; there is **no single-row voucher projection**, so voucher detail cannot be built; and **two of the four voucher lifecycle states are unreachable** — `activating` is not in the CHECK (F-7, unauthorized) and **nothing anywhere writes `expired`** (measured: the only state write in the codebase is `revoke`). Also: the voucher **code is withheld from every Admin projection by design** (Decision 3), and sessions carry **no username and no site** (site is derivable via the voucher). Work split into UI-only, needs-approval, and blocked |
| **docs/93** | **v0.2 — HotSpot, Vouchers, Sessions (built, dev/test).** Migration **021** adds the two approved projections and nothing else: **`mt_admin_services()`** (whose `status` is **recorded, not observed** — a test asserts HotSpot liveness stays `UNMEASURED` whatever it returns) and **`mt_admin_voucher(uuid)`** (column list **identical to the list**, so a detail view cannot reach a withheld field one row at a time; `code` withheld). The read boundary goes from eleven to **thirteen**. Voucher lifecycle is declared **server-side**: two states reachable, three not, each saying what is missing — and **no `active` or `expired` voucher was manufactured**. Operator/engineer split: Router Detail shows concise verdicts, Diagnostics carries the evidence. `WAN interface` becomes **`WAN interface assignment — RECORDED`**. 1,488 assertions |
| **docs/94** | **v0.3 Admin surface inventory (inventory only, nothing built).** Fourteen destinations classified A–E. **Two findings outrank any single screen: every one of the nine Admin list projections is UNBOUNDED** — no `LIMIT`, no pagination, no server-side filter, while the customer-facing services *are* bounded — and **seven of fourteen screens are flat unfiltered lists**, because the shared `list()` helper offers no search or filter at all. Confirms HotSpot should become **index + detail**, and records that *"needs attention"* has no honest definition while RADIUS and HotSpot state are `NOT MEASURED`. Finds **`mt_admin_customer(uuid)` and `api.customer(id)` both built and unused** — Customer Detail is the cheapest gain. Notes there is **no operational workflow screen and cannot be one** until a write route is bound. Bounding the projections is tabled as the next boundary decision |
| **docs/95** | **Admin list read contract — decision packet (nothing chosen).** Covers **ten** list projections, not nine: `mt_admin_voucher_batches()` is one too. Three measured constraints: **eight of ten order by a non-unique column**, and **vouchers are already degenerate** — 17 rows, **3 distinct `created_at`**, because `issueBatch` writes a batch in one transaction and `now()` is transaction time, so an 8-row batch is one tie group with arbitrary internal order; **not one index serves an Admin list order** (`EXPLAIN` shows Seq Scan + Sort — every index is tenant-leading because the *customer* API was the design target); and **the house has "bounded", never "paginated"**, so there is no cursor format or page-size precedent to follow. Records that **bounding the lists silently breaks every count the panel shows**. Nine decisions **A-1…A-9** are tabled unchosen; **A-7 (stable ordering) and A-9 (indexes) are the load-bearing pair**. **D: none of this work is hardware-dependent** — it can complete while F6-B stays closed |
| **docs/96** | **Installability audit — INSTALLABLE WITH CONDITIONS.** Classified **B, an independent Domain-B service**, measurably **not** a UCRM plugin; before it, **D** — a project with no artifact. 67-check disposable test: install → serve → simulate → uninstall with zero residue, then a second install from the artifact alone. |
| **docs/97** | **B-1 CLOSED — no credential in the repository.** Migrations declare privilege, the installer supplies credentials. Six `PASSWORD '<literal>'` clauses removed; `rolpassword` NULL until install provisions one. Five defects found by running it, including a refusal that fired *after* the credentials were applied. |
| **docs/98** | **UISP/uCRM integration audit.** Plugin contract MEASURED from two plugins already running on the DishNet server: ZIP with root `manifest.json`, `main.php` on a ~5-minute tick, **one** public file, `ucrm.json` → `pluginAppKey` → `api/v1.0/*`, staff identity via `/current-user`, storage SQLite. **RC1 is NOT installable that way and must not be forced.** **I-1: Domain B is an unlinked second customer master.** |
| **docs/99** | **Customer identity — two models, neither chosen.** Model A (uCRM source of truth) vs Model B (entity + link). **I-2**: `mt_principals` duplicates uCRM contact data. Superseded in part by `docs/101`. |
| **docs/100** | **Identity census — READ-ONLY, development databases. NOT E-2.** Proved by execution that an existing customer **cannot be deleted**; 27 FKs; **`mt_principals.phone` is the authentication key**; `credential_hash` dead; uCRM **does** expose stable service ids. Operator questions reduced 13 → 4. |
| **docs/101** | **Q5 = C (both workflows). L-1 CLOSED.** Equipment-first is `device.customer_id = NULL` — no placeholder customer. **Determined for approval:** customer link **1:1**, service link **1:1**, **columns not a link table**, service identity is the uCRM **service id**, **`UNCLAIMED` is a predicate not a state**, writes `dnb_adminwrite`-only, plus a **coherence rule** no FK can express. Measured: unclaimed devices already invisible to every tenant. |
| **docs/102** | **The commercial identity boundary, measured.** Every operation classified. **The effective boundary is RLS + triggers + functions, NOT grants** (proved by execution). **`mt_services`/`mt_sites` have no production writer.** **No single gate exists** — site and voucher reference no device. Redemption must **never** consult uCRM; audit must **never** be gated. **B-2**: the customer-plane commercial writes are not behind definer functions. |

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
| **Open** | Decision **5** (rate-limit and timing numbers) — **gated**, see `docs/89` §3.2; Decision **6** (retention). Decision **4** (front-desk activation): **no second redemption path required** (`docs/88` Part B) |
| **The next gate** | **The operator runs the census — docs/79 is the handoff.** This session cannot (verified access limitation, docs/78 §1.1) and will not probe further. Then: review evidence → decide B → finalize backfill/migration → explicit F6 authorization |
| **Voucher/AAA path** | **NON-CONFORMING — NOT PRODUCTION CAPABLE** (`docs/86`, remediation designed in `docs/87`). The implementation drifted from Decisions 1 and 3 before production. No deployment occurred |
| **Admin prototype** | v0.2 built (`docs/93`): HotSpot, voucher detail, richer sessions, operator/engineer split, thirteen projections. v0.1 (`docs/91`): a coherent `SIM-` estate in its own database, five-section Router Detail, three new honesty gaps recorded. No gate moved |
| **Plugin boundary** | Built (`docs/90`). Installable manifest, lifecycle, API entry point; panel separated as a consumer; two-plane Admin UI. No gate moved |
| **Installability** | **INSTALLABLE WITH CONDITIONS** (`docs/96`). It is **B — an independent Domain-B service**, measurably **not** a UCRM plugin; before this it was **D**, a project with no artifact. A 90-file tarball, a preflight, a bootstrap step and a 32-check disposable install test now exist, proven install → serve → simulate → uninstall with **zero residue**. **B-1 is CLOSED** (`docs/97`): the migrations create every login role with no password, the installer provisions one at install time, and 0 of 6 burned credentials authenticate on a cluster that enforces scram. **B-2 remains OPEN** — no staff authentication (W-4), so the panel renders only under the development identity. Release candidate `0.1.0-rc1`, content digest `aa48b4b4…b1db63c7`, 67-check disposable test. **Not installed anywhere.** No gate moved |
| **F6-A (application)** | Building. Admin **read** boundary and read-only Admin UI done; the Admin **write** floor (W-1/W-2/W-3) is implemented in the development schema with **no write route bound**. `DenyAllIdentity` is still the production Admin binding (W-4 open) |
| **F6** | **NOT AUTHORIZED.** It is not the next step, and closing 2a did not bring it closer to being authorized |
| **UISP/uCRM integration** | **AUDITED, nothing decided** (`docs/98`). The plugin contract is MEASURED from two plugins already running on that server: ZIP with root `manifest.json`, `main.php` on a ~5-minute tick, **one** public file (`public.php?page=`), `ucrm.json` → `pluginAppKey` → `api/v1.0/*` with `X-Auth-App-Key`, staff identity by forwarding the uCRM session cookie to `/current-user`, storage = data dir + SQLite. **RC1 is NOT installable through it and must not be forced**: every Domain-B control lives in PostgreSQL features a plugin cannot have. Recommendation **R-1 — a thin bridge plugin, Domain B stays a separate service**. **I-1: Domain B is today a second, unlinked customer master** — `ucrm_client_id`'s only writer is a test fixture. **U-1…U-5 must be decided first** |
| **Customer identity model** | **DESIGNED, NOT CHOSEN** (`docs/99`). Model A (uCRM client is the source of truth, `mt_customers` a projection, `ucrm_client_id NOT NULL`) and Model B (Domain-B entity carrying a linked `ucrm_client_id` with provenance) are compared factually; **neither is recommended**. **U-2 cannot be closed by choosing a value — it needs the production census.** New findings: **I-2**, `mt_principals` duplicates uCRM's name/phone/email; **`mt_services` has no uCRM service reference at all**; and the estate's own precedent (`026_lte_financial_ledger.sql`) is a link table with `linked_by`. The staff trust boundary is **U-7: S-1 proxy or S-2 signed assertion** — Domain B must never take a staff identity from a browser |
| **Identity census** | **READ-ONLY, development databases — NOT E-2** (`docs/100`). Production state stays unmeasured. Schema truths that generalise: an existing customer **cannot be deleted** (proved by execution); **`mt_principals.phone` is the authentication key**; deleting a principal **silently nulls `sold_by`/`created_by`**; uCRM exposes stable service ids Domain B cannot represent |
| **Q5 — CLOSED: C, BOTH; L-1 CLOSED** | Customer-first **and** equipment-first (`docs/101`). Equipment-first is a **device with no customer**, already built — never a placeholder customer. Both converge at `mt_device_assign` |
| **Commercial boundary** | **MEASURED, nothing built** (`docs/102`). **No single gate** — `service → site → plan → voucher` completes a sale with no router. The rule is enforced at a **set** of operations, never at `mt_customer_create`. **B-2**: plans and vouchers write directly under RLS, so gating them means definer functions with audit — the largest item U-1 implies |
| **Write paths** | **MEASURED** (`docs/103`). **Four of the five identity entities have NO production write path**: `mt_customers` has a function but no caller, and `mt_principals`/`mt_services`/`mt_sites` are written **only by `Plugin/Simulator.php`** — which the release package excludes, so an installed RC1 cannot create a principal, service or site at all. `src/Customers/` is an **empty directory**. 35 operations inventoried with audit and idempotency status; **`POST /me/vouchers` is the only idempotent route**. Two project rules recorded: the **security evidence hierarchy** (grants are the weakest evidence, never proof) and **repository-edit safety** (an edit must assert anchor, count and result; exit 0 is not evidence) |
| **Onboarding spine** | **DESIGNED, nothing built** (`docs/104`). The four missing writers are **one lifecycle**, not four tasks: customer caller → principal → service → site. Cardinality read off the schema — **`mt_principals.phone` is unique GLOBALLY**, `mt_services.kind` permits exactly one value, and the **minimum valid customer is a name and an actor** (no uCRM link, service, site, router or phone). **Steps 1–6 produce a commercially active customer with no hardware**, which is why the uCRM link cannot be gated on device assignment. New defect **O-1**: `mt_sites` has two independent FKs, so a tenant can attach its site to **another customer's service** — proved by execution as `dnb_app` under RLS. No disclosure (RLS still hides the service row), but a confirmed integrity defect and **cross-tenant denial**: the victim can never end that service. Latent only because no production writer creates a site — **the spine must close it before building one**. **U-1 deliberately NOT closed** |
| **Unanswered factual input** | whether a site may have several MikroTik HotSpot routers (docs/68 §2.6b). It did **not** block Decision 2a and is **not** settled by it |
