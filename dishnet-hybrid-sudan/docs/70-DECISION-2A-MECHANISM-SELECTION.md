# 70 — Decision 2a: production mechanism — **CLOSED**

**Status: DECISION RECORD. Decision 2a is CLOSED (approved 2026-09-21).**

> **Decision 2a — CLOSED.** The production mechanism enforcing the Decision 2a
> security requirement under site-bound policy is **C-b — site-keyed dynamic SQL
> source-address restriction**, using `dnb_cred_site`, `dnb_site_nas` and the
> `EXISTS` predicate against the authorized NAS/source-address set.
> **Huntgroups are retired as a production candidate.**

§8 holds the decision as approved, its accepted consequences, and what it
explicitly does **not** authorize. **Nothing here is authorized to be built.**

**How to read this document.** §2 and §4 are **measured facts** — what was
observed on a disposable instance. §6 is **production implications** — what a
selection would mean for production, none of it measured there. That distinction
is deliberate and survives the closure: the decision is closed, the production
behaviour of the chosen mechanism is still *unmeasured in production*.

Scope: choose between the two mechanisms proven in docs/68 §2.4, under the
policy closed in docs/68 §2.5 (**A / SITE-BOUND**).

| | |
|---|---|
| **Mechanism 1** | **Huntgroups** — `Huntgroup-Name` on the credential, the site→router set in FreeRADIUS's `huntgroups` file (E7, E8, E9) |
| **Mechanism 2** | **C-b** — a source-address predicate inside the SQL authorize query, the set in database rows (docs/68 §2.4f) |

Neither entered this gate as the favourite. C-b performing better in a
disposable experiment was not a reason to select it, and is not used as one
below — §7 records the reasoning that did carry the decision, and names what was
excluded from it.

**Boundaries this document does not touch.** Voucher code → control plane only;
generated AAA credentials → RADIUS only (docs/67, Decision 3). F1–F13 frozen.
Decisions 1, 2b, 3 and 7 closed. No production FreeRADIUS, database, schema or
privilege change is proposed *by this document* — §6 names the changes any
selection would later require, as items for their own authorized gate.

---

## 1. Why more measurement was needed before selecting

Two gaps made the existing evidence insufficient to choose from.

**Gap 1 — the evidence was keyed on the wrong thing.** docs/68 §2.4f measured
C-b with a **customer** key. Decision 2b then closed as **site**-bound. A
customer key and a site key are not the same mapping and cannot be assumed to
behave the same way.

**Gap 2 — the invariant had never been measured, in either mechanism.** The
instruction for this gate names it:

> A voucher credential must never authenticate against a NAS that is outside the
> voucher's authorized site, and **a site/router reassignment must not leave
> stale authorization behind.**

The first clause was measured (E2, E3, E7, E8, §2.4f). The second — *reassignment*
— was not. E9 measured only the **addition** direction: a huntgroup change that
*grants* authorization takes effect solely on a full restart. The invariant is
about the **removal** direction. Whether removal behaves the same way does not
follow from E9, so it was measured rather than inferred.

Two disposable runs on the existing throwaway instance, no production contact,
synthetic values throughout:

- `tools/audit/f6_site_bound_reassign.sh` — C-b re-keyed on **site**, plus
  reassignment, plus the degenerate and broken-infrastructure cases
- `tools/audit/f6_huntgroup_reassign.sh` — the huntgroup **removal** direction

---

## 2. New evidence

### S1–S9 — C-b keyed on site (two-table indirection)

The mapping under test, both tables **additive** to the stock schema:

```sql
dnb_cred_site (radius_username text PRIMARY KEY, site_id text NOT NULL)
dnb_site_nas  (site_id text NOT NULL, nas_ip text NOT NULL,
               PRIMARY KEY (site_id, nas_ip))
```

```sql
authorize_check_query = "
  SELECT c.id, c.UserName, c.Attribute, c.Value, c.Op
    FROM radcheck AS c
   WHERE c.Username = '%{SQL-User-Name}'
     AND EXISTS (SELECT 1 FROM dnb_cred_site cs
                   JOIN dnb_site_nas sn ON sn.site_id = cs.site_id
                  WHERE cs.radius_username = c.UserName
                    AND sn.nas_ip = '%{Packet-Src-IP-Address}')
   ORDER BY c.id"
```

`SYN-USER-1` → `site-a` → `127.0.0.1`.  `SYN-USER-2` → `site-b` → `127.0.0.2`.
**No credential row is modified in any case below.**

| | case | U1@A | U1@B | U2@A | U2@B | reading |
|---|---|---|---|---|---|---|
| **S1** | baseline, each at its own site | Accept | Reject | Reject | Accept | site isolation holds on a site key |
| **S2** | `site-a` gains a **second** router (one INSERT) | Accept | **Accept** | Reject | Accept | multi-NAS per site, no restart, credentials untouched |
| **S3** | **`127.0.0.1` reassigned `site-a`→`site-b`** (one UPDATE) | **Reject** | Reject | **Accept** | Accept | **stale authorization gone and new authorization live in the same statement** |
| **S4** | `site-a` has no routers | Reject | Reject | Reject | Accept | fail closed; `site-b` unaffected |
| **S5** | U1 has no site row | Reject | Reject | Reject | Accept | fail closed |
| **S6** | U1, wrong password, at its own site | Reject | — | — | — | the site test is **additive to** authentication, not a substitute |
| **S7** | mapping intact (control for S8/S9) | Accept | — | — | — | — |
| **S8** | `dnb_site_nas` **dropped** — the query itself errors | **Reject** | — | — | — | fail closed on broken infrastructure |
| **S9** | both mapping tables gone | **Reject** | — | — | — | fail closed |

S8/S9 were verified against the server's own log, not the client's word: the log
carries `rlm_sql_postgresql: ERROR: relation "dnb_site_nas" does not exist`
followed by a real Access-Reject, and the client prints `TIMEOUT` (not `Reject`)
when nothing answers — so these are rejects, not silence. The credential's
`Cleartext-Password` row was still present throughout. **Destroying the mapping
denies access; it does not restore unrestricted access.**

S2, S3, S4 and S5 all ran **with no restart of any kind**.

**Correction to S2's reading (recorded docs/71 §1.3).** S2 was reported above as
*"a site gains a second router,"* which it was. It was also something not
reported. Its baseline was `site-a={127.0.0.1}`, `site-b={127.0.0.2}`; the INSERT
made `site-a={127.0.0.1, 127.0.0.2}` while `site-b` still held `127.0.0.2` — so
**`127.0.0.2` was in both sites at once**, belonging to two different customers,
and the measured result was `U1@B = Access-Accept` **and** `U2@B = Access-Accept`:
two customers' credentials authenticating at the same router.

The predicate behaved correctly — it authorized exactly what the table said. The
point is about the **table**: `PRIMARY KEY (site_id, nas_ip)` permits a NAS shared
between two customers' sites. **This does not weaken Decision 2a**; the mechanism
did what it was asked. It changes what the mapping's *integrity* rests on, and it
is why docs/71 §2.1 proposes `PRIMARY KEY (nas_ip)` instead — a change to the
shape recorded here, conditional on a factual question nobody has answered
(docs/71 §9 Q2) and **not** made unilaterally.

### E10 — huntgroup removal also requires a full restart

`SYN-USER-1` carries `radcheck: Huntgroup-Name == site-a`. The credential is
**never touched**. `127.0.0.1` is then reassigned from `site-a` to `site-b` in
the `huntgroups` file:

| step | U1 @ 127.0.0.1 | |
|---|---|---|
| baseline, `127.0.0.1` in `site-a` | Accept | correct |
| **file rewritten** — the router now belongs to `site-b` | **Accept** | **stale authorization persists** |
| **after SIGHUP** | **Accept** | **stale authorization persists** |
| **after full restart** | Reject | correct at last |

**E10 is the direct measurement of the invariant's failure mode.** Stale
authorization survives the provisioning change and survives a SIGHUP. It ends
only when someone restarts a production RADIUS server.

E10 also confirms the fail-closed case for a mapping that no longer contains the
router (final row), and that huntgroup indirection is structurally correct — the
credential named a site, not a router, and was never rewritten.

---

## 3. The sixteen production requirements

Under **A / SITE-BOUND**. **M** = measured here or in docs/68 §2.4c/§2.4f;
**D** = derived from a measurement; **NM** = not measured.

| # | Requirement | Huntgroups | C-b (site-keyed) |
|---|---|---|---|
| 1 | **Site → authorized NAS set** | `huntgroups` file, one line per (site, router), on the FreeRADIUS host. Set semantics **M** (E7) | `dnb_site_nas` rows in the `radius` database. Set semantics **M** (S2) |
| 2 | **Publisher privileges to maintain the mapping** | **None.** The mapping is not database state; the publisher never touches it. The credential row is an ordinary `radcheck` insert inside the existing `publish()`. **Decision 7's boundary is unchanged** **D** | `dnb_cred_site` is per-credential and folds into `publish()`/`unpublish()`, so `dnb_pub` keeps zero table privileges. **But `dnb_site_nas` is a different lifecycle and needs a second writer** — see §5.1 **D** |
| 3 | **Fail-closed** | **M** — unknown/emptied group rejects (E10 final row); E4's negative result stands | **M** — empty set (S4), missing credential mapping (S5), **dropped tables** (S8, S9). Authentication still enforced (S6) |
| 4 | **Multi-NAS per site** | Supported **M** (E7); every change costs a restart | Supported **M** (S2); one INSERT, no restart |
| 5 | **Router / site reassignment** | **M (E10)** — file edit no effect, SIGHUP no effect, **full restart required** | **M (S3)** — one UPDATE, atomic, immediate, no restart, no credential rewritten |
| 6 | **Stale mapping removal** | **VIOLATED for the whole restart window M (E10).** The window is bounded by operator discipline, not by the mechanism | **Satisfied M (S3, S4).** Removal is the same row operation and takes effect at the next Access-Request |
| 7 | **Voucher activation** | `publish()` writes `Cleartext-Password` + `Huntgroup-Name`. No new table. **But a *new* site's group does not exist until a restart, so that site's first vouchers reject D (E9)** — and under C20 Q3 vouchers are generated per site, locally, which is exactly when a site is new | `publish()` writes the password row + one `dnb_cred_site` row. Effective immediately, new site or not **M (S1)** |
| 8 | **Voucher revocation** | `unpublish()` deletes the credential's `radcheck` rows → immediate reject. No restart, mapping irrelevant **D** | Same, plus deleting the `dnb_cred_site` row as housekeeping. No restart **D**. **Equivalent** |
| 9 | **Customer / site isolation** | **M** (E8 at a customer key, E10 baseline at a site key) | **M** (S1) |
| 10 | **Cross-customer isolation** | `mt_sites.customer_id` is **NOT NULL**, so a site belongs to exactly one customer and cross-customer isolation is a **consequence** of the site→NAS mapping being right, not a second check. **Identical in both mechanisms** — and it means one mapping now carries both properties, which is requirement 2 again, with higher stakes | as huntgroups |
| 11 | **Operational change path** | Edit a file on the FreeRADIUS host, then restart. A deployment/config-management action, outside the control plane | A row change in the `radius` database. Needs a writer and a defined path (§5.1) |
| 12 | **Restart requirement** | **Every mapping change. M in both directions** (E9 add, E10 remove) | **One restart, once,** to install the query. Zero thereafter **M** (S2–S5 ran with none) |
| 13 | **Failure / recovery** | The file is outside the credential store's backup boundary, so a restore can **silently reinstate stale authorization** at the next restart, with no signal **D**. A missing file stops the server from starting (`preprocess` requires it) — fail-closed by outage **M** | The mapping is in the **same database** as the credentials, inside one backup/restore boundary, so it cannot desynchronize from them that way **D**. Broken tables fail closed **M (S8, S9)** |
| 14 | **Auditability** | Only whatever tracks the file (git, config management). No actor, no timestamp, nothing in the database. The restart is visible in the server log | Ordinary database writes — auditable **if the path is built to audit them**. Neither mechanism is auditable by default **NM in both** |
| 15 | **Interaction with the AAA Publisher (Decision 7)** | **No change.** One extra `radcheck` row inside the existing definer function; `dnb_pub` keeps EXECUTE-on-two-functions and no table privileges (docs/66 §2.3) | **Decision 7 must be extended** by a second, separately-privileged writer for `dnb_site_nas`. This is a real cost, stated as one in §5.1 |
| 16 | **New database state path** | **None** | **Two tables on two lifecycles.** Both additive — no FreeRADIUS table altered, no column added, docs/65 §12.0's prohibition intact — but additive is not free |

---

## 4. Measured facts

1. **C-b works on a site key, not only a customer key** (S1). Re-keying to match Decision 2b did not weaken it.
2. **A site may hold several routers under either mechanism** (S2, E7). Neither forecloses the question left open in docs/68 §2.6b, so **2a is not blocked by it.**
3. **C-b satisfies the reassignment clause of the invariant; huntgroups do not** (S3 vs E10). This is the only requirement where the two differ in kind rather than in degree.
4. **Huntgroup staleness is not brief and is not self-correcting** (E10). It survives the file edit and a SIGHUP, and ends only at a full restart.
5. **C-b needs no restart for any mapping change** (S2–S5); its single restart is the one-time query installation.
6. **C-b fails closed in every degenerate case measured, including destruction of its own mapping tables** (S4, S5, S8, S9), and does not weaken password authentication (S6).
7. **Revocation is equally immediate under both** and depends on neither mapping.
8. **Under site-bound, cross-customer isolation is a consequence of the site mapping, not a separate control** — because `mt_sites.customer_id` is NOT NULL. True of both mechanisms.
9. **Huntgroups leave Decision 7's privilege boundary untouched; C-b does not** (requirements 2, 15, 16).
10. **Neither mechanism audits a mapping change by itself** (requirement 14).

---

## 5. Remaining risks and unknowns

### 5.1 C-b needs a second writer that Decision 7 did not scope — the main cost

The authorize query runs in the `radius` database, so the mapping must live
there. It splits across **two lifecycles**:

| table | written when | by |
|---|---|---|
| `dnb_cred_site` | voucher **activation** | the AAA Publisher — same lifecycle as `publish()`, folds inside the existing definer functions, **no new privilege** |
| `dnb_site_nas` | **device provisioning** | *not the publisher* |

Letting the publisher write `dnb_site_nas` would let a voucher-activation path
change the authorization boundary for **every** voucher at a site. Combined with
fact 8 — that this one mapping now carries cross-customer isolation too — that
is more authority than Decision 7 deliberately granted. So C-b requires a
**separate** provisioning writer, narrowly scoped, and that extension must be
reviewed on its own rather than assumed to follow from selecting C-b.

**This is the strongest argument against C-b and it is not dismissed below.**

### 5.2 Not measured

- **The production instance has never been touched.** Its authorize query is
  username-only (docs/68 §2.4b). Every measurement here is a throwaway
  FreeRADIUS 3.2.5 on the stock schema. Behaviour is *expected* to carry over;
  it is **not measured there**, and cannot be without an authorized change.
- **No load or scale measurement.** The `EXISTS` join adds work per authorize.
  Both lookups are PK-served (`dnb_cred_site(radius_username)`,
  `dnb_site_nas(site_id, nas_ip)`), but no timing exists.
- **Concurrency during reassignment.** S3 measured one UPDATE against an idle
  server. Reassignment while sessions are live at that router was not measured,
  nor was the effect on already-established sessions (authorization is checked at
  Access-Request; an existing session is not re-authorized, so a reassignment
  presumably does not disconnect it — **derived, not measured**, and it matters
  operationally).
- **Mapping-change auditing** in either mechanism (requirement 14).
- **Restore/backup divergence** (requirement 13) is reasoned from where the state
  lives, not measured.

### 5.3 A code-review hazard C-b introduces

`%{Packet-Src-IP-Address}` is interpolated into SQL text, not bound as a
parameter. The value is **server-derived** (E3) — taken from the UDP header,
parsed as an address, and not client-settable without spoofing the source, which
forfeits the reply — so this is not a live injection path. The hazard is that the
*pattern* invites a later developer to interpolate something that **is**
client-supplied. If C-b is selected, that belongs in the C1 guard tests at the F6
gate, as an explicit assertion that no client-asserted value reaches this query.

### 5.4 Unchanged open items

- Whether a site may have several MikroTik HotSpot routers (docs/68 §2.6b) is
  still unanswered — and, per fact 2, **does not block 2a.**
- No C1 guard test exists yet. Mandatory **at** the F6 implementation gate
  (docs/68 Part 1), not after it.
- C-a, C-c and C-d remain unmeasured candidates. Selecting between the two
  *proven* mechanisms does not retire them; it makes them unnecessary.

---

## 6. Production implications

Neither mechanism is free, and **both** require a one-time authorized change to
production FreeRADIUS. What differs is what recurs.

**If huntgroups were selected**

- A site→router mapping lives in a file on the RADIUS host — outside the control
  plane, outside the database, outside the credential backup boundary.
- **Every** site or router change requires a **full restart of production
  FreeRADIUS**, which interrupts authentication for **every site of every
  customer**, not just the one being changed.
- That creates an incentive that runs against the invariant: the more carefully
  an operator batches restarts to avoid disruption, the **longer stale
  authorization survives** (E10). The failure mode gets worse the more prudently
  the mechanism is operated.
- **Site onboarding requires a global restart** before the new site's vouchers
  work (requirement 7) — and per C20 Q3 vouchers are generated per site,
  locally, so this is the routine growth path, not an edge case.
- Decision 7 is untouched. No new database state. No new writer.

**If C-b were selected**

- One authorized change to production FreeRADIUS installs the query, with one
  restart; mapping changes then need none.
- Two additive tables in the `radius` database. No FreeRADIUS table altered.
- Decision 7's privilege boundary must be **extended** by a narrowly-scoped
  provisioning writer for `dnb_site_nas` (§5.1), as its own reviewed step.
- Site onboarding and router reassignment become row changes, effective at the
  next Access-Request, with no service interruption.
- The mapping becomes load-bearing for site **and** cross-customer isolation
  (fact 8), so its write path needs the same seriousness as the credential path —
  guard tests, and an audited writer.

---

## 7. Recommendation, with reasoning

**Recommended: C-b, site-keyed, with the two-table indirection.**

The reasoning rests on one requirement and one structural observation, not on
C-b having looked better in a prototype.

**The requirement.** The invariant singled out for this gate has two clauses.
Both mechanisms satisfy the first. **Only C-b satisfies the second** (S3 vs E10).
This is not a matter of convenience: a security boundary that is corrected only
when someone remembers to restart a production RADIUS server is not a boundary
that holds. E10 shows the stale state surviving both the provisioning change and
a SIGHUP, and §6 shows the operational incentive pushing the restart *later*. A
control whose failure window widens as it is operated more carefully is the wrong
control for a boundary that also carries cross-customer isolation (fact 8).

**The structural observation.** C-b's costs are **design** costs, paid once, in
the open, at a gate: two additive tables, one query change, one extra writer to
scope. Huntgroups' cost is an **operational** cost, paid repeatedly, by whoever
is on duty, and it is paid in exactly the situations the business will hit most
often — adding a site, adding a router, moving a router. Requirement 7 makes this
concrete: under huntgroups, onboarding a site needs a global restart before its
first voucher authenticates.

**Why huntgroups' one real advantage does not carry the decision.** Requirements
2, 15 and 16 favour huntgroups genuinely — no new database state, no new writer,
Decision 7 untouched. That advantage is real and §5.1 does not minimise it. But
it buys privilege-boundary simplicity at the price of correctness in requirement
6, and the extension C-b needs is *boundable*: one table, one lifecycle, one
narrowly-scoped writer, reviewable before anything is built. A permanent
correctness gap cannot be bounded the same way.

**Explicitly not part of the reasoning:** that C-b performed better in the
disposable experiment; that C-b is simpler (it is not — it adds two tables and a
writer); and any inference from C20 Q1/Q2 or the ISP/operator hierarchy, which
are business-model inputs and not a tenant layer.

**Conditions the recommendation carries.** Approving C-b would not authorize
building it. It would still require: the Decision 7 extension in §5.1 reviewed on
its own; the C1 guard tests at the F6 gate including §5.3's assertion; and the
production query change as its own authorized step.

---

## 8. The decision, as approved

**Approved 2026-09-21. Decision 2a is CLOSED.**

> **Decision 2a — production mechanism.** Adopt **C-b (site-keyed dynamic SQL
> source-address restriction)** as the production mechanism enforcing the
> Decision 2a security requirement under site-bound policy, via
> `dnb_cred_site` + `dnb_site_nas` and the `EXISTS` predicate against the
> authorized NAS/source-address set in the authorize query. **Huntgroups are
> retired as a production candidate** and remain what docs/68 §2.4 calls them:
> a measured mechanism, recorded for its evidence value.

### 8.1 The measured evidence the decision rests on

Recorded here in full because a closed decision must carry its evidence with it.
All of it was measured on a **disposable** FreeRADIUS instance (§2); none of it
was measured in production (§5.2).

| | Measured | Where |
|---|---|---|
| 1 | **Site isolation proven** — a credential authenticates at its own site's NAS and is rejected at another site's | S1 |
| 2 | **Multiple NAS per site proven** — one INSERT, no restart, no credential rewritten | S2 |
| 3 | **Atomic site reassignment proven** — one `UPDATE` moves a router between sites | S3 |
| 4 | **Old-site authorization stops and new-site authorization starts** in that same statement, **without any credential rewrite and without a FreeRADIUS restart** | S3 |
| 5 | **Empty mapping fails closed** — a site with no routers authorizes nothing | S4 |
| 6 | **Missing credential mapping fails closed** — a credential with no site authorizes nowhere | S5 |
| 7 | **Destroyed mapping tables fail closed** — dropping `dnb_site_nas`, then both tables, yields real Access-Rejects (verified in the server log, with the credential's password row still present) | S8, S9 |
| 8 | **Huntgroup removal leaves stale authorization until a full restart** — surviving the file edit and a SIGHUP | **E10** |

Item 8 is why huntgroups are retired: it is the measured failure of the
invariant this gate was called to protect — *a site/router reassignment must not
leave stale authorization behind.*

Two further measured facts constrain how the decision may be read: the site test
is **additive to** password authentication and does not replace it (S6), and
**both** mechanisms supported multiple routers per site, so the still-open
question in docs/68 §2.6b did not block this decision and is not settled by it.

### 8.2 Consequences accepted — none of them authorized to build

Accepted with the decision, each to be designed and reviewed **as its own step**:

| | Consequence | State |
|---|---|---|
| 1 | **Decision 7 is extended** by one **narrowly scoped provisioning writer** for `dnb_site_nas` (§5.1) | **NOT DESIGNED. The next gate.** Must be designed and reviewed before any implementation |
| 2 | The two **additive** mapping tables, `dnb_cred_site` and `dnb_site_nas`; no FreeRADIUS table altered | **NOT CREATED** |
| 3 | The production FreeRADIUS **authorize-query change** plus its one-time restart | **NOT MADE — a separately authorized step** |
| 4 | The **no-client-asserted-value invariant** added to the C1 guard tests (§5.3) | **NOT WRITTEN — due at the F6 gate**, not after it |

### 8.3 What this decision does not authorize

- **F6 remains NOT AUTHORIZED.** `dnb_app` still holds `EXECUTE` on
  `mt_voucher_redeem`, exactly as docs/63 recorded it.
- **Production FreeRADIUS is untouched.** Its authorize query is still
  username-only (docs/68 §2.4b).
- **The production database is untouched.** No table, column, constraint,
  privilege or role has been created.
- **F1–F13 remain frozen**; Decisions 1, 2b, 3 and 7 remain closed as recorded.
- The open multiple-routers-per-site question (docs/68 §2.6b) is **not** answered
  by this decision.

### 8.4 The next gate

**The Decision 7 extension — the provisioning-writer design.** Not F6
implementation.

The reason is §5.1 and fact 8 of §4 together: `dnb_site_nas` now carries **site
isolation and cross-customer isolation at once**, and it is written on the
*device-provisioning* lifecycle, not the publication lifecycle. Letting the AAA
Publisher write it would let a voucher-activation path move the authorization
boundary for every voucher at a site. That writer needs its own security
boundary, designed and reviewed, **before** anything is implemented.
