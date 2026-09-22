# 113 — Audit integrity remediation (A-1)

**Design plus controlled disposable-test evidence. No production change, no
migration applied, no application code modified.** Every test below ran against
`dnb_sim` inside a rolled-back transaction; residue was checked and is zero.

---

## 1. Every role that can write `mt_audit_log` — measured by execution

Each login role connected in its own session, set a tenant context, and
attempted `INSERT`, `UPDATE` and `DELETE` against a real audit row. **Every
session's control confirmed it had connected**, so no refusal below is a false
negative.

| Role | direct INSERT | UPDATE | DELETE | EXECUTE `mt_audit_write` | Intended capability | **Required final** |
|---|---|---|---|---|---|---|
| `dnb_admin` | **ALLOWED** | refused | refused | no | Admin plane (unbound) | **NONE** |
| `dnb_app` | **ALLOWED** | refused | refused | no | customer API — **writes six audit sites today (F-8)** | **NONE**, after F-8 |
| `dnb_worker` | **ALLOWED** | refused | refused | no | intent worker | **NONE**, via a definer |
| `dnb_adminapi` | refused | refused | refused | no | Admin **read** | NONE ✓ already |
| `dnb_adminwrite` | refused | refused | refused | no | Admin **write** | NONE ✓ already |
| `dnb_radius` | refused | refused | refused | no | accounting ingest | NONE ✓ already |
| `dnb_def_audit` | **yes (grant)** | no | no | **owns it** | the audit boundary | **the only writer** |
| `dnb_def_prov` | no | no | no | **yes** | calls the audit boundary | unchanged ✓ |
| `dnb_def_auth/net/work/admin` | no | no | no | no | other definers | unchanged ✓ |
| `dnb` (owner) | yes | yes | yes | — | migrations, tests | owner; out of scope |
| `dnb_plain` | no | no | no | no | **unexplained** | see §1.2 |

### 1.1 Two findings from the matrix

- **Three roles can forge an audit row: `dnb_admin`, `dnb_app`, `dnb_worker`** —
  not one. `docs/112` A-1 named two; `dnb_app` is the third, and it is the
  **customer-facing HTTP role**.
- **`UPDATE` and `DELETE` are refused for every role, including those holding
  the grant.** The append-only trigger holds universally. So the exposure is
  **forgery only, never tampering or erasure** — which is the worse half,
  because an appended lie cannot be removed.

### 1.2 `dnb_plain` — a stray

It exists in this cluster with no privileges on `mt_audit_log`, and **nothing in
`migrations/`, `src/`, `tests/`, `tools/` or the installer creates it.** It is
not one of the twelve. Recorded as a **development-cluster stray**, and as a
reason the production census must **enumerate roles**, not assume the twelve.

---

## 2. The final audit boundary

```
HTTP / worker role
        │  EXECUTE only — no table privilege
        ▼
SECURITY DEFINER function  (dnb_def_prov / dnb_def_work / …)
        │  business mutation + audit, one transaction
        ▼
mt_audit_write()           owned by dnb_def_audit
        │
        ▼
mt_audit_log               append-only trigger, RLS forced
```

> **INVARIANT A-1.** No HTTP-facing role and no worker role may hold direct
> `INSERT` on `mt_audit_log`. Audit rows are written only through the audit
> definer boundary.

**Preserved unchanged:** the append-only trigger; audit inside the same
transaction as the mutation; the actor as a **parameter from the identity
boundary**; `actor_kind` limited to `principal | staff | system`; and **no
secret, key or credential material in audit detail**.

### Why RLS is not sufficient here — the precise diagnosis

Migration 015 granted blanket table privileges deliberately. Its own comment
states the reasoning:

> *"No role is a member of another… Each is subject to RLS on every table; the
> difference between them is only which SECURITY DEFINER functions they may
> call."*

That reasoning holds for business tables, where RLS plus constraints bound what
a row can say. **It fails for `mt_audit_log`, because RLS constrains only which
tenant a row belongs to — it cannot constrain whether the row is true.** A
forged row with `actor = 'someone-else'` satisfies
`customer_id = mt_current_customer()` perfectly.

**A-1 is therefore not a mistake in migration 015's logic; it is a table for
which that logic does not apply.**

---

## 3. `dnb_admin` — why it has the grant, and who depends on it

| | |
|---|---|
| **Source** | `migrations/015_role_separation.sql:73` — `GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES … TO dnb_worker, dnb_admin`, plus `ALTER DEFAULT PRIVILEGES` so **future tables inherit it** |
| **Rationale** | uniform privileges, RLS as the differentiator (§2) |
| **Already carved out** | `REVOKE DELETE ON mt_migrations` — finding S5, so the pattern of narrowing this grant is established |
| **Production callers** | **NONE.** The only caller of `Database::admin()` in the entire repository is **`src/Plugin/Simulator.php:65`** |
| **Is the simulator shipped?** | **No** — excluded from the release package (`docs/96`) |

> **No production-capable route connects as `dnb_admin`.** `Database` documents
> `adminApi()` and `adminWrite()` as *"separate from `admin()` on purpose"*, and
> the Admin API uses those.

**Consequence, stated plainly:** revoking `dnb_admin`'s privileges would break
**only the simulator** — which builds its estate *through the real write paths*,
deliberately. That is a real cost, not a detail: the simulator is how the panel
is exercised. **Two options, neither chosen here:** point the simulator at the
owner role `dnb` (already used by migrations and tests), or give it a
development-only identity. **Do not silently break it.**

---

## 4. `dnb_worker` — what it actually writes

Measured from the code, not the grants. The worker writes exactly three
statements' worth of tables directly:

```
INSERT INTO mt_intents      ← IntentQueue::enqueue
UPDATE mt_intents           ← claim / markSent / markConfirmed / recordFailure
INSERT INTO mt_audit_log    ← AuditLog::record  (intent.confirmed, intent.failed)
```

| | |
|---|---|
| **Needs direct table writes?** | **Yes — for `mt_intents`.** The lease/claim mechanic is a direct `UPDATE`, and no definer function wraps it |
| **Needs direct `mt_audit_log`?** | **No.** Its two audit rows (`intent.confirmed`, `intent.failed`) are exactly the kind W-1 moves inside a function |

> So the worker cannot be reduced to zero table privileges like
> `dnb_adminwrite`. **The audit privilege can be removed; the `mt_intents`
> privilege cannot, until the intent lifecycle itself is moved behind definer
> functions.** That is separate work and is **not** proposed here.

---

## 5. The remediation — three tiers, by cost

| Tier | Role | Action | New objects needed? | Breaks |
|---|---|---|---|---|
| **T1** | `dnb_admin` | `REVOKE` the blanket grant (or at minimum `mt_audit_log`) | **none** | the simulator only (§3) |
| **T2** | `dnb_worker` | move `intent.confirmed` / `intent.failed` behind a definer owned by `dnb_def_work`, granted EXECUTE on `mt_audit_write`; then `REVOKE INSERT ON mt_audit_log` | **one function** | nothing, once the function exists |
| **T3** | `dnb_app` | the **F-8 remediation** — the six caller-written audit sites move inside definer functions; then `REVOKE` | **six call sites + functions** | the customer API until done |

> **Can A-1 be remediated without schema changes?**
> **T1 — yes, entirely.** `REVOKE` only; no DDL on any table, no new function, no
> column, no constraint.
> **T2 — one new function** (a schema object, but no table change).
> **T3 — the largest**, and it is F-8's existing scope, not new work.

**Also required in every tier:** `ALTER DEFAULT PRIVILEGES … REVOKE`, or future
tables silently re-acquire the grant. **Revoking the current grant without
revoking the default is a fix that expires.**

---

## 6. The eight existing functions are unaffected — **proven**

`dnb_adminwrite` may EXECUTE eight functions: the seven provisioning functions
plus `mt_customer_create`. Run as `dnb_adminwrite`, rolled back:

| | Test | Result |
|---|---|---|
| **control** | `INSERT INTO mt_audit_log` directly | **`ERROR: permission denied for table mt_audit_log`** |
| **control** | `SELECT count(*) FROM mt_audit_log` | **`ERROR: permission denied for function mt_current_customer`** — it cannot even evaluate the RLS policy |
| **act** | `mt_customer_create('PROBE-AUDIT-PATH','probe-actor')` | **succeeded**, returned a uuid |
| **verify** | audit rows written by that call | **`1`** |
| residue | | **0** |

> **A caller that cannot write — or even read — `mt_audit_log` still produces a
> complete audit row through the definer path.** Revoking direct INSERT from
> `dnb_admin`, `dnb_worker` or `dnb_app` therefore **cannot** break the eight
> functions: they depend on `dnb_def_audit`'s privilege, never the caller's.

That is the whole safety case for the remediation, and it is measured rather
than argued.

---

## 7. The nine future writers

Per `docs/112`, all nine are `EXECUTE` to **`dnb_adminwrite` only**, which holds
zero table privileges — proven in §6 to be sufficient.

> **Binding: no spine function may be granted EXECUTE to `dnb_admin`,
> `dnb_worker` or `dnb_app`**, because each can independently forge an audit row
> until its tier completes. A function whose audit row can be preceded or
> followed by a forged one gains nothing from being unskippable.

**T1 should therefore precede the first spine writer**, since it costs a
`REVOKE` and removes one of the three forging roles outright.

---

## 8. The remediation order

| # | Step | Verification |
|---|---|---|
| 1 | **Establish the replacement controlled path** (T2's definer; T3's functions) | the new function writes an audit row from a caller with no table privilege — the §6 test shape |
| 2 | **Verify all callers** | every `AuditLog::record` site is either inside a definer or migrated; grep + test, not inspection |
| 3 | **Revoke** the unsafe direct privileges — **and the default privileges** | `has_table_privilege` reads false for all three roles |
| 4 | **Positive controls still pass** | the eight functions still write audit rows; the customer API still audits its six sites; the worker still records `intent.confirmed` |
| 5 | **Negative controls** | each of the three roles attempts a direct INSERT and is **refused** — the §1 matrix re-run, expecting all `refused` |
| 6 | **Append-only still holds** | UPDATE and DELETE refused for every role, including the owner path |
| 7 | **Attribution intact** | actor, `actor_kind` and target survive the move; a spot-check that the actor is the identity-boundary parameter and not a GUC |
| 8 | **Only then expose new writers** | the spine, behind W-4 |

**And the control on the controls:** step 5's test must be shown to **fail** if
the revoke is reverted. A negative test that passes before the fix proves
nothing.

---

## 9. No architecture expansion

**Not reopened:** P-B · P-C · S-A · the O-1 design · the Domain-B standalone
boundary · the uCRM/guest boundary. This document changes **privileges**, not
architecture.

---

## 10. Status

| | |
|---|---|
| **Roles with direct audit-write capability, measured** | **`dnb_admin`, `dnb_app`, `dnb_worker`** (plus `dnb` the owner, and `dnb_def_audit` by design) |
| **Target model** | `HTTP/worker role → definer function → dnb_def_audit → mt_audit_log`; **zero** direct INSERT for HTTP and worker roles |
| **Can A-1 be remediated without schema changes?** | **T1 yes — `REVOKE` alone.** T2 needs one function; T3 is F-8's existing scope |
| **Tamper/erase risk** | **none** — append-only refused `UPDATE`/`DELETE` for every role tested |
| **Cross-tenant risk** | **none** — RLS refused the cross-tenant write (`docs/112` A-1b) |

**Blockers unchanged and still open:** A-1 (T1/T2/T3) · O-1 census + migration ·
the non-tenant idempotency store · `session.disconnect` replay · N-1/N-2/N-3 ·
W-4 · E-2 · U-1 (Q7).

---

**Stopping here. Suite unchanged: 1,599 assertions, 27 suites. No production
change, no migration applied, no application code modified, zero residue.
Nothing authorized to build.**
