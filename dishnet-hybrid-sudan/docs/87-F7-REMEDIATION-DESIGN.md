# 87 — F-7 remediation design: bringing the voucher/AAA path back to Decisions 1, 3 and 7

**Status:** DESIGN ONLY. **Nothing implemented. No schema, migration, code,
privilege, configuration or deployment changed.** Suite unchanged at **1,228
assertions green**.

**Classification of the current implementation:
`NON-CONFORMING — NOT PRODUCTION CAPABLE`.** No production impact: nothing has
been deployed.

**This document reopens nothing.** Decisions 1, 2a, 2b, 3 and 7 are closed and
are treated here as the specification. Where the current code disagrees with
them, **the code is wrong.** Most of what follows is not new design — it is
`docs/66` and `docs/67` restated as a build order, with the gaps named.

---

## A. CURRENT NON-CONFORMING FLOW

Measured in `docs/86`, on a throwaway database through migration 020.

```
  ISSUE  (POST /api/v1/me/vouchers, authenticated customer principal)
    |
    +-- mt_voucher_batches row
    +-- mt_vouchers rows           state 'unused', site_id set
    +-- mt_hotspot_users rows      <-- WRITTEN HERE. Decision 1 says redemption.
    |     radius_username = customers.radius_ref || '-' || replace(code,'-','')
    |                              <-- the commercial code IS the AAA username.
    |                                  Decision 3 says it never enters this path.
    +-- intent 'voucher.publish' (per BATCH)
    |     delivery = assertRadiusBacked() — asserts the hotspot is RADIUS-backed
    |                and publishes nothing, because nothing needs publishing
    +-- route writes audit 'voucher.issued' {count}
    |
  REDEMPTION
    |
    +-- mt_voucher_redeem(code)    EXISTS, and NOTHING CALLS IT.
    |     VoucherService::redeem() has no caller: no route, no worker, no PWA.
    |     Sets state 'unused' -> 'active', sets activated_at and expires_at.
    |     Takes ONE argument. No NAS. No site. No actor. No audit row.
    |     Does not touch mt_hotspot_users — the row already exists.
    |
  RADIUS
    |
    +-- radcheck / radreply        NEVER WRITTEN BY THIS APPLICATION.
          RadiusPublisherPort has no production caller; Bindings exposes
          publisher(), nothing consumes it. No credential is ever published.
```

**What this flow would do if a real FreeRADIUS were pointed at it.** Nothing —
there is no credential to authenticate against, because publication does not
happen. The danger is not today's behaviour; it is that the *shape* of the code
is Model A. The moment anything publishes `mt_hotspot_users` to `radcheck`, a
printed, unsold voucher becomes a live bearer credential, which is precisely the
measured hazard Decision 1 rejected (`docs/66` §1.1 row 1).

Six conformance defects, all measured:

| # | Defect | Contradicts |
|---|---|---|
| **N-1** | AAA registry state created at issue | Decision 1 (Model B) |
| **N-2** | `radius_username` reversible from the commercial code | Decision 3 (P2) |
| **N-3** | `RadiusPublisherPort` has no production caller | Decision 7 §2.2 |
| **N-4** | Site binding not enforced at activation; `mt_hotspot_users` has no site | Decisions 2a, 2b |
| **N-5** | `mt_voucher_redeem()` has no entry point | Decision 1, Decision 3 §4 |
| **N-6** | Revoke leaves the registry row in place | Decision 7 §2.9 |

Two further gaps, not conformance defects but blocking the target flow:

| # | Gap |
|---|---|
| **N-7** | `mt_vouchers.state` admits only `unused / active / expired / revoked`. The three states Decision 7 §4 requires — `activating`, `activation_failed`, `revoking` — do not exist. |
| **N-8** | No publication store exists. `mt_intents` must **not** be reused: `docs/66` §2.7 explicitly refuses to inherit its `max_attempts = 5` / `deadline = 7 days` timings, because a guest is waiting. |

---

## B. TARGET CONFORMING FLOW

This is `docs/66` §6 and `docs/67` §5 composed, with the Decision 2a mechanism
attached. It is the specification, not a proposal.

```
 ISSUE — commercial only
   POST /api/v1/me/vouchers            authenticated customer principal
     +-- mt_voucher_batches, mt_vouchers   state 'unused', site_id NOT NULL
     +-- NO mt_hotspot_users row
     +-- NO radcheck / radreply row
     +-- NO publication
     +-- NO 'voucher.publish' intent          <-- there is nothing to publish
     => a printed voucher is an inert commercial instrument. It authenticates
        nowhere, because no credential exists anywhere.

 ACTIVATION — the guest, through the captive portal
   POST /api/v1/portal/redeem   {code, nas}        unauthenticated
     |
     +-- rate limit, per code and per NAS       (numbers = Decision 5)
     +-- mt_portal_redeem(code, nas)  ONE control-plane transaction:
     |     1. resolve the voucher by code
     |     2. state must be 'unused'          -> else uniform 'invalid'
     |     3. SITE CHECK: the claimed NAS must belong to voucher.site_id
     |                                         -> else uniform 'invalid'
     |     4. state 'unused' -> 'activating'   (expires_at NOT set yet)
     |     5. mt_hotspot_users row written HERE, with the GENERATED username
     |     6. mt_publications row queued, idempotency key = voucher_id
     |     7. audit row, in this same transaction  (§E)
     |     8. return an opaque TICKET
     |
     +-- the endpoint then waits, bounded, polling the publication row
           server-side                          (docs/66 §2.4)

 PUBLICATION — the AAA Publisher, a separate worker
   dnb_publisher  (control plane: EXECUTE on claim/complete/fail, no tables)
   dnb_pub        (radius db:     EXECUTE on publish/unpublish, no tables)
     |
     +-- mint username and password from a CSPRNG, independently,
     |   neither derived from the code, from each other, or from any
     |   customer label                          (docs/67 §3.1, §3.2)
     +-- publish(username, password, expiration, reply_items, site_id):
     |     DELETE radcheck/radreply/dnb_cred_site WHERE username = $1;
     |     INSERT radcheck   (Cleartext-Password, Expiration)
     |     INSERT radreply   (reply items from the plan)
     |     INSERT dnb_cred_site (username, site_id)   <-- Decision 2a, C-b
     |   one transaction, delete-then-insert so a retry converges
     +-- on success: voucher 'activating' -> 'active', publisher sets
     |   expires_at  (docs/66 §2.8 — the guest's clock starts at access)
     +-- on permanent failure or past deadline: 'activation_failed'

 RETRIEVAL
   within the bounded wait : 200 {state:ready, username, password, expires_at}
   otherwise               : 202 {state:activating, ticket}
   GET /api/v1/portal/activation/{ticket}
                           : ready | activating | failed | unknown

 AUTHENTICATION
   guest POSTs the GENERATED username+password to the ROUTER's login endpoint
     -> router -> RADIUS Access-Request
     -> authorize query: password check AND EXISTS(dnb_cred_site JOIN
        dnb_site_nas ON site) against %{Packet-Src-IP-Address}
     -> Access-Accept only at a NAS authorized for that voucher's site
     -> radacct -> HTTP -> dnb_radius -> mt_session_account
```

### B.1 The code boundary, stated as an invariant

```
the code reaches   : the guest, the portal page, the control plane.
the code NEVER reaches: the router, FreeRADIUS, radcheck, radreply, radacct,
                        radpostauth, dnb_cred_site, mt_hotspot_users,
                        mt_audit_log, any log, any AAA credential field.
```

Inside the control plane the code may identify the voucher **during the
redemption transaction only**. It is not stored from the request, and per
`docs/67` §5 the audit carries **prefix + hash**, never the code.

### B.2 One design rule the evidence forces, stated explicitly

**`dnb_cred_site` must be written from `voucher.site_id`, never from the NAS the
portal was given.**

The NAS in a captive-portal redirect is carried by the guest's browser and is
therefore **guest-asserted**. Its only legitimate use at redemption is to reject
early when it does not match the voucher's site. If it were allowed to *decide*
the binding, a guest could choose which site their credential is valid at.

This is safe as designed because enforcement is two-layer: the portal's check is
correctness, and the **security** enforcement is C-b at FreeRADIUS against
`%{Packet-Src-IP-Address}`, which the guest cannot forge. A guest who lies about
the NAS gets a credential bound to the voucher's real site and then fails to
authenticate where they actually are — self-defeating, not exploitable. The rule
keeps it that way. It also satisfies `docs/70` §5.3: **no client-asserted value
reaches the authorize query**, because what is written comes from the voucher
row.

### B.3 Issue-time behaviour after correction — exactly

| Store | At issue | At activation |
|---|---|---|
| `mt_vouchers` | **created**, `unused`, `site_id` NOT NULL | state transitions only |
| `mt_voucher_batches` | **created** | untouched |
| `mt_hotspot_users` | **nothing** | **created**, generated username, no secret |
| `mt_publications` | **nothing** | **created**, one per voucher |
| `radcheck` / `radreply` | **nothing** | written by the publisher, after activation |
| `dnb_cred_site` | **nothing** | written by the publisher, from `voucher.site_id` |
| intents | **none** | none (publication is not an intent) |

`mt_hotspot_users` becomes what its own table comment already claims —
*"Redeeming a voucher creates one of these and nothing else"* — the contradiction
recorded in `docs/65` §0 resolved **by the code moving**, exactly as `docs/66`
§8 requires.

---

## C. GAP LIST

Every component that must change. **None of this is authorised; this is the
inventory a build gate would work from.**

### C.1 Control-plane schema

| # | Change | Note |
|---|---|---|
| S-1 | `mt_vouchers_state_check` extended with `activating`, `activation_failed`, `revoking` | Decision 7 §4. Additive. |
| S-2 | **New** `mt_publications` | `voucher_id` **UNIQUE** (the idempotency key, `docs/66` §2.5), `state`, `attempts`, `deadline_at` (minutes), `claimed_at`, `completed_at`, `error_class`, `ticket_hash`. **Never** the code, **never** the password. |
| S-3 | `mt_hotspot_users.radius_username` becomes the generated value | No schema change; the writer changes. Its `UNIQUE` already gives estate-wide uniqueness, which `radcheck` does not enforce (`docs/67` §6 pt 2). |
| S-4 | Ticket storage **hashed** | `docs/67` §3.4. Reuse `Authenticator::hash` (HMAC-SHA256 with `DNB_TOKEN_PEPPER`) — the discipline already applied to auth tokens. |
| S-5 | `mt_vouchers.site_id NOT NULL` | Already **CLOSED** as a decision, **not migrated** (`docs/77` §1). Activation's site check depends on it. **Gated on the production census.** |
| S-6 | Whether `mt_hotspot_users` needs `site_id` | **Open.** The authoritative site→NAS mapping for C-b lives in the `radius` database. A control-plane copy would be for reconciliation only. Do not add it to "make the join work". |

### C.2 Control-plane roles and functions

| # | Change |
|---|---|
| R-1 | **New role `dnb_publisher`** (LOGIN): EXECUTE on the claim/complete/fail functions, **no table privileges**. Modelled on `dnb_worker`. `docs/66` §2.3. |
| R-2 | **New role `dnb_portal`** (LOGIN, proposed): EXECUTE on `mt_portal_redeem` and the ticket-status function, **no table privileges**. *Not in `docs/66`; proposed here* because the portal is unauthenticated and must not run as `dnb_app`, which today holds `EXECUTE` on `mt_voucher_redeem` — the standing **F6** finding. **Needs approval.** |
| F-1 | **New** `mt_portal_redeem(p_code text, p_nas text)` — the whole activation transaction, SECURITY DEFINER, owned by a definer role, returning an opaque ticket and never the reason for failure. |
| F-2 | **New** `mt_publication_claim / _complete / _fail` |
| F-3 | **New** `mt_activation_status(p_ticket_hash text)` — ticket-keyed, never code-keyed (`docs/66` §2.4). |
| F-4 | **Drop** `mt_voucher_redeem(text)` — replaced by F-1. Leaving it would leave an unaudited, non-site-checked activation path, which is the mistake migration 020 avoided by dropping the four old provisioning signatures. |
| F-5 | `mt_audit_write` **EXECUTE granted to the portal/publication definer owner.** Today only `dnb_def_prov` holds it (migration 020), so an activation audit row is currently impossible. |
| F-6 | Sweeps: `active → expired` with unpublish; `activating` past deadline → `activation_failed`. |

### C.3 The `radius` database — a separate, separately reviewed step

| # | Change |
|---|---|
| X-1 | **New role `dnb_pub`** (LOGIN): EXECUTE on two functions, **no table privileges at all** — cannot read `nas` (which holds `nas.secret`), `radacct`, `radpostauth`, or the credentials it writes. |
| X-2 | **New** `publish(...)` / `unpublish(...)` SECURITY DEFINER functions, owned by the `radius` role, delete-then-insert so retries converge (`docs/66` §2.6). |
| X-3 | **New** `dnb_cred_site` + `dnb_site_nas` tables — Decision 2a, C-b. **Do not exist** (`docs/70` §8.2). |
| X-4 | `authorize_check_query` gains the `EXISTS` predicate on `%{Packet-Src-IP-Address}`. **Separately authorized** (`docs/70` §8.3). |

> **`docs/66` §9a is superseded on mechanism.** It was written when the site→NAS
> restriction was a huntgroups file. Decision 2a **retired huntgroups** and chose
> C-b (`docs/70` §8). The publisher's "second kind of state" is now
> `dnb_site_nas`/`dnb_cred_site` — database rows, not server-side configuration,
> and therefore **no FreeRADIUS restart** (measured, S3). §9a's privilege
> caution still applies: the publisher gets no filesystem or configuration
> access.

### C.4 Application code

| # | Change |
|---|---|
| A-1 | `VoucherService::issueBatch()` **stops** writing `mt_hotspot_users` and **stops** enqueuing `voucher.publish`. |
| A-2 | `VoucherService::radiusUsername()` **deleted.** Its only caller is A-1. A guard test must assert no code derives an AAA identifier from a voucher code. |
| A-3 | `VoucherService::redeem()` **deleted**, replaced by a portal service calling `mt_portal_redeem`. Do not make the existing method reachable. |
| A-4 | **New** `CredentialMinter`: two independent CSPRNG draws. `random_int`/`random_bytes` are already the house CSPRNG (`CodeGenerator`, `Authenticator`). Username ≥128 bits, carrying no customer label; password distinct from the username (`docs/67` §3.2 — `docs/33`'s identical pair would put the password into `radpostauth.username`). |
| A-5 | **New** `AaaPublisher` worker, consuming `RadiusPublisherPort` — which **already exists and is already tested**, and is the one piece of this that is built. |
| A-6 | **New** portal routes: `POST /api/v1/portal/redeem`, `GET /api/v1/portal/activation/{ticket}`, both `auth:false`, both outside `TenantContext` (no tenant is known until the code resolves) — so both must carry their own transaction, which `mt_portal_redeem` provides. |
| A-7 | `RouterOsDelivery`: `voucher.publish` / `voucher.revoke` cases removed or repointed. Publication is not an intent. |
| A-8 | Revocation becomes `active → revoking → revoked` on unpublish confirmation, plus an ordered `session.disconnect` (`docs/66` §2.9, §2.10) — whose delivery case **does not exist** (W-6). |
| A-9 | Reconciler, four drift classes, **asymmetric**: may remove access automatically, may **never** grant it (`docs/66` §2.12). |

### C.5 What must NOT change

- `mt_vouchers` stays authoritative; `radcheck` is a projection, never a source.
- No FDW, no `dblink`, no two-phase commit across the two databases.
- Accounting ingestion stays RADIUS → control plane over HTTP on `dnb_radius`;
  `dnb_radius` is **not** reused for publication.
- No FreeRADIUS table altered, no column added, no constraint created.
- Migration 020's W-1/W-2/W-3 floor stands unchanged.

---

## D. ACTOR MODEL

**The existing taxonomy is sufficient. No new `actor_kind` is required.**

`mt_audit_log` constrains it in the schema, not by convention:

```
mt_audit_log_actor_kind_check :: CHECK (actor_kind = ANY (ARRAY['principal','staff','system']))
```

| Candidate | Verdict |
|---|---|
| `principal` | **Wrong.** A `principal` is a row in `mt_principals` — a customer's authenticated user. A captive-portal guest is not one, and never will be: `docs/64` settled that `customer_id` is never disclosed to an unauthenticated caller. Using it would attribute a guest's act to the customer's staff. |
| `staff` | **Wrong.** DishNet staff. Not present. |
| `system` | **Correct, and not a fudge.** The act being recorded is *the control plane activated voucher X on presentation at NAS Y*. No identity is presented, by design. The system is what acted. |
| `guest` (new) | **Rejected.** It would be a schema change to a contract three other paths depend on, and it would assert a product fact — that guests are actors in our system — that no closed decision establishes. It also buys nothing: there is no guest identity to record, so the column would hold a constant. |

**Proposed row shape** (existing schema, no migration to the audit contract):

| Column | Value |
|---|---|
| `actor` | `'portal'` — the component that performed it |
| `actor_kind` | `'system'` |
| `customer_id` | from the **voucher row**, never from the caller |
| `target_type` | `'voucher'` |
| `target_id` | the voucher **UUID**, never the code |
| `source` | the **claimed** NAS identifier |
| `detail` | see §E |

### D.1 The one architectural gap this does expose

**`source` would hold a guest-asserted value.** For the six customer routes,
`source` is `REMOTE_ADDR` — not client-settable. For the portal it would be the
NAS identifier from a captive-portal redirect, which the browser carries.

This is **not** a reason to invent an actor kind; it is a reason to label the
field honestly. Two options, both needing a decision:

- **D-1a** record it as an explicit claim: `detail.nas_claimed`, leaving `source`
  as `REMOTE_ADDR` — consistent with the other six sites, and never mistakable
  for a verified fact;
- **D-1b** record it in `source` and accept that `source` means different things
  on different paths.

**D-1a is recommended.** It keeps one meaning for `source` across the whole
audit log and makes the claim's status self-describing. **It requires your
decision.**

---

## E. F-7 AUDIT DESIGN

Only after §B and §C. Auditing today's unreachable function would be a control
that never runs.

| Question | Answer |
|---|---|
| **What is audited** | The control-plane state transition, not the publication outcome. Two events: `voucher.activation_started` (`unused → activating`) and `voucher.activated` (`activating → active`, written by the publisher's completion). Plus `voucher.activation_failed` on permanent failure or deadline. |
| **Who writes it** | The function that performs the transition, via `mt_audit_write` — **never the caller**. F-8's measured result is the argument: route-written audit is skipped the moment a second caller exists. |
| **Actor** | `actor='portal'` / `actor_kind='system'` for the start; `actor='publisher'` / `'system'` for completion and failure. |
| **Target** | The voucher **UUID**. Never the code. Never the generated username. |
| **Transaction boundary** | `voucher.activation_started` is in the **same transaction** as the state change and the registry row — that transaction exists and is the one `mt_portal_redeem` opens. `voucher.activated` is in the **same transaction as the completion state change** in the control plane. It is **not** atomic with the `radcheck` write, and cannot be: `docs/66` §2.14 forbids cross-database atomicity, which is why `activating` is a state. The audit trail therefore records *what the control plane believes*, and the reconciler (§C.4 A-9) is what detects divergence. Stating otherwise would be a false claim of atomicity. |
| **Failure behaviour** | A refused redemption — unknown, spent, revoked, expired, wrong site, unknown NAS, rate-limited — **writes no audit row at all**. It is not an act that happened, and a row would make the audit log a code oracle for anyone who can read it (`docs/65` §8, `docs/67` §4.2). A **permanent publication failure** does audit, because by then a real transition occurred. |
| **Never in `detail`** | the voucher code; the generated password; the generated username; `radius_ref`; any database, publisher or FreeRADIUS error text. Permitted: `{"nas_claimed": "...", "site_id": "...", "attempt": n, "error_class": "transient\|permanent"}` — error **class**, not error text. |
| **Code prefix + hash** | `docs/67` §5 permits `prefix + hash` in audit. Recommended **only** for the unknown-code case — and since §E already says unknown codes write **no row**, it should not be needed at all. If an operational need for it appears later, it is its own decision. |

---

## F. F-8 REMEDIATION DESIGN — separate stream, lower severity

**Kept separate deliberately.** Customer mutations and staff mutations have
different identity boundaries, and merging them to share one mechanism would
undo what W-3 and Decision 8 established. **Nothing here is authorised.**

What is already sound and must be preserved: audit is transactional
(`Kernel` wraps every authenticated handler in `TenantContext::run`); actor and
customer come from the bearer token and cannot be forged; RLS independently
refuses a row claiming another customer; `actor_kind` is CHECK-constrained; no
sensitive field or voucher code appears in any detail.

| Ref | Defect | Proposed remediation | Severity |
|---|---|---|---|
| **F-8.1** | Service-level mutation bypasses route audit — **measured**: calling `VoucherService::issueBatch()` directly wrote no audit row | Move each audit write to the act: inside the repository/service method, or inside a definer function as W-1 did. The customer path keeps `actor_kind='principal'` with the principal id, and must **not** adopt the staff model. | **High** |
| **F-8.2** | `auth/request-code`, `auth/verify`, `auth/logout` audit nothing — a successful login leaves no record of who or from where | Audit inside the existing auth definer functions, which already own the state change. `verify` → the principal; `logout` → the principal; `request-code` has **no authenticated actor yet** and is its own question. | **High** |
| **F-8.3** | The three auth routes run outside any transaction (`auth:false` skips `TenantContext`) | Give them their own transaction boundary, as `mt_portal_redeem` will have. | Medium |
| **F-8.4** | `/internal/radius/accounting` audits nothing | Probably correct to leave: it is per-packet telemetry on a separate identity, and auditing it would flood the log. **Confirm as a D-3 scope decision rather than fix by default.** | Low |
| **F-8.5** | `session.disconnect_requested` audits a *request* whose delivery case does not exist | No change now. Record so nothing later reads it as evidence a session was disconnected. | Low (trap later) |
| **F-8.6** | `dnb_app` holds direct `INSERT` on `mt_audit_log` | Once audit moves into functions, apply the W-1 shape — a narrow definer writer no HTTP role may call — with a **separate** grant for the customer path. | Medium |

**Constraint on all of the above:** do not widen `mt_audit_log`'s `actor_kind`
CHECK to make any of it fit.

---

## G. TEST PLAN

Every item is an **execution** proof. None may be satisfied by reading code —
this project has now recorded four controls that ran without testing anything
(`docs/85` §4.1). Tests marked **RADIUS** need the disposable FreeRADIUS
instance, never production.

### G.1 The twelve required proofs

| # | Proof | How it is proven |
|---|---|---|
| **T-1** | Voucher issue creates **no usable AAA credential** | After `issueBatch`: assert `mt_hotspot_users` has **zero** rows for the batch, `mt_publications` zero, and the `radius` database's `radcheck`/`radreply` zero for every issued voucher. Positive control: the vouchers themselves exist and are `unused`. |
| **T-2** | The voucher code cannot become an AAA username or password | For every issued voucher: assert the code, its dash-stripped form, and its case variants appear in **no** `radcheck.username`, `radcheck.value`, `radreply.*`, `mt_hotspot_users.radius_username`, `dnb_cred_site.username`, audit `detail`, or any captured log. **Plus a source guard**: no file in `src/` derives an AAA identifier from a code (the `radiusUsername` shape must not return). |
| **T-3** | Two vouchers receive unrelated generated credentials | Activate two vouchers of the **same batch, same plan, same customer**. Assert all four values distinct, no common prefix beyond chance, username ≠ password for each, and neither derivable from the code. Assert ≥128 bits of entropy by construction, not by inspecting one sample. |
| **T-4** | A site-A voucher cannot authenticate at site B | **RADIUS.** Activate a voucher bound to site A; attempt authentication from site B's NAS source address. Expect **Access-Reject**. Positive control: the same credential at site A's NAS gives Access-Accept. This is C-b (S1) re-proved through the real activation path rather than a hand-built fixture. |
| **T-5** | An unused voucher cannot authenticate | **RADIUS.** Issue, do **not** activate, and attempt authentication with every plausible derivation of the code as username. Expect Access-Reject for all. This is the Model A hazard, proven absent. |
| **T-6** | Activation publishes only after a valid redemption | Assert `radcheck` rows appear **only** after `mt_portal_redeem` succeeded and the publisher completed. Attempt publication for a voucher still `unused` and assert it is refused. |
| **T-7** | Publication failure does not produce a usable credential | Force the `radius`-side publish to fail (permanent). Assert: voucher is `activation_failed`, **no** `radcheck` row exists, the portal returns `{"state":"failed","reference":…}` with HTTP **200** (never 500), and no partial credential is left behind. Then force a **transient** failure and assert the voucher stays `activating`, the ticket stays valid, and a retry converges. |
| **T-8** | Duplicate and concurrent redemption are safe | Same code twice while `activating` → `activating` both times, **one** publication row (the `voucher_id` UNIQUE key). Same code while `active` and unexpired → the **same** credentials returned, not a second publication. Two connections racing one code → one wins, the other blocks on the row lock and then observes the same outcome. Assert exactly one `mt_hotspot_users` row and one `radcheck` username throughout. |
| **T-9** | Revoke prevents future authentication | **RADIUS.** Revoke an `active` voucher; assert `active → revoking → revoked` only on unpublish confirmation, that `radcheck`/`radreply`/`dnb_cred_site` rows are **gone**, and that authentication then fails. Assert a `revoked` state alone does **not** count as revoked while the credential still exists — the defect `docs/65` §4.2 measured. |
| **T-10** | Active-session handling follows Decision 7 | Assert the **order**: unpublish first, then `session.disconnect`. Assert that unpublishing alone does **not** end a running session (a property of RADIUS, asserted as a documented expectation, not a claim that we tested a live session). W-6 blocks the delivery half — the test must assert the **ordering and the queued intent**, and must **not** assert delivery. |
| **T-11** | No voucher code in AAA, logging or audit | Superset of T-2, extended to `radpostauth`, `radacct`, the publication store, ticket storage, and the captured `error_log` stream for a full issue→activate→authenticate→revoke cycle. |
| **T-12** | The opaque activation status is not a voucher oracle | Assert unknown, spent, revoked, expired, wrong-site, unknown-NAS and rate-limited **all** return byte-identical `{"state":"invalid"}` with HTTP 200. Assert the ticket endpoint is **ticket-keyed only** and that presenting a *code* to it is not a supported input. Assert response-time variance does not separate the classes beyond a stated tolerance — a timing oracle is still an oracle. |

### G.2 Additional proofs the evidence demands

| # | Proof |
|---|---|
| **T-13** | **`dnb_cred_site` is written from `voucher.site_id`, never from the claimed NAS** (§B.2). Redeem with a NAS claim for a different site and assert: refused, and no binding row written. Then redeem correctly and assert the binding row's `site_id` equals the voucher's. |
| **T-14** | **Least privilege, by execution.** `dnb_pub` holds **no** privilege on `nas`, `radacct`, `radpostauth`, `radgroupcheck`, `radgroupreply`, `radusergroup`, `nasreload` — and cannot `SELECT` the credentials it writes. Attempt each and assert refusal. `dnb_publisher` holds no table privileges. `dnb_portal` (if approved) holds no table privileges and no EXECUTE beyond the two portal functions. |
| **T-15** | **Republication converges.** Call `publish()` twice for one username; assert exactly **two** `radcheck` rows (`Cleartext-Password`, `Expiration`), not four — the duplication `docs/65` §3.2 measured under a naive amend. |
| **T-16** | **`expires_at` is set by the publisher, not by redemption** (`docs/66` §2.8). Assert it is NULL while `activating`, and that a slow publication does not shorten the voucher's window. |
| **T-17** | **The old path is gone.** `mt_voucher_redeem(text)` no longer exists; `VoucherService::redeem()` and `radiusUsername()` no longer exist; nothing in `src/` references them. The migration-020 lesson: a surviving non-conforming signature would be worse than no fix. |
| **T-18** | **Guard: no client-asserted value reaches the authorize query** (`docs/70` §5.3), re-asserted through the activation path — mandatory at the F6 gate, per `CLAUDE.md`. |
| **T-19** | **Every migration proves its own effect**, as 020 does: a non-owner `GRANT` is a warning, not an error, and an `ADD FOREIGN KEY` can report `convalidated` having validated nothing under FORCE RLS. |

### G.3 Ordering

T-1, T-2, T-3, T-17 and the source guards are pure control-plane and can be
proven **first**, before any `radius`-side work — they are the conformance
proofs for Decisions 1 and 3 and are the cheapest evidence that the drift is
corrected. T-4, T-5, T-9, T-10 and T-15 need the disposable FreeRADIUS instance
and belong to the F6-B gate.

---

## H. What this document does not do

Implements nothing. Changes no schema, migration, code, role, privilege,
configuration or deployment. Does not apply migration 020 to production. Does
not deploy C-b. Does not build the AAA Publisher. Does not create `dnb_site_nas`
or `dnb_cred_site`. Does not decide Q2. Does not implement staff authentication.
Adds no Admin write buttons. Does not start F6-B. Reopens no closed decision.
Contacts no production system. Suite unchanged at **1,228 assertions green**.

### H.1 Decisions this design needs before implementation

| Ref | Needed decision |
|---|---|
| **P-1** | Approve `dnb_portal` as a distinct unauthenticated-path role (§C.2 R-2). Not in `docs/66`; proposed here. Without it the portal runs as `dnb_app`, which is F6. |
| **P-2** | §D.1 — `nas_claimed` in `detail` (**recommended**) versus in `source`. |
| **P-3** | Decision 5's numbers: the bounded wait, the publication deadline, the ticket lifetime, and the per-code and per-NAS rate limits. The design cannot be built without them. |
| **P-4** | §C.1 S-6 — whether `mt_hotspot_users` carries `site_id` for reconciliation. |
| **P-5** | S-5 — `mt_vouchers.site_id NOT NULL` migration, **gated on the production census**. |
| **P-6** | Decision 4 (front-desk activation): whether a receptionist may activate on a guest's behalf. It would add a **second** entry point with a `principal` actor, and it changes §D. |
| **P-7** | W-6 — `session.disconnect` has no delivery case, so §C.4 A-8 cannot complete. |
