# 85 — Admin write remediation: W-1, W-2, W-3 implemented

**Status:** IMPLEMENTED in the development/test schema. **Production untouched.**
**Migration:** `migrations/020_admin_write_boundary.sql`
**Authorises:** nothing further. No Admin write route or button is bound.
**Supersedes nothing.** Implements the three decisions accepted against `docs/84`.

---

## 0. What this increment is, and what it is not

`docs/84` inventoried twenty-four state-changing Admin operations and found six
defects. Three of them were accepted for immediate remediation — W-1, W-2, W-3 —
on the explicit condition that **no Admin write UI is bound yet**.

That condition is met. `src/Api/AdminRoutes.php` still contains no call to any
write function, and a test asserts it (§4, W-3 group).

This work changes **function signatures, one constraint pair, and one role**. It
does not change production, does not touch Domain A, does not touch FreeRADIUS,
and does not resolve W-4, W-5 or W-6.

---

## 1. W-1 — audit is written by the function, in the same transaction

### 1.1 The defect

`docs/84` F-1: `mt_device_assign()` wrote no audit row at all, and the audit
that did exist for other operations was written by the **caller**, in
`src/Api/Routes.php`, after the function returned. Six such call sites exist.
Audit that lives in a route file is audit that a second caller silently skips —
and a second caller is exactly what an Admin write API would be.

### 1.2 The shape of the fix

`mt_audit_log` carries RLS + FORCE and a tenant policy, so a function owned by
`dnb_def_prov` cannot insert into it. Rather than weaken that, migration 020
adds one narrow writer:

```
dnb_def_audit          NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
                       NOINHERIT NOBYPASSRLS
  ├─ INSERT on mt_audit_log, via policy dnb_def_audit_mt_audit_log_insert
  ├─ NO SELECT, NO UPDATE, NO DELETE, NO TRUNCATE
  └─ owns mt_audit_write(uuid,text,text,text,text,text,text,jsonb)
       EXECUTE granted to dnb_def_prov and to nobody else
```

`dnb_app`, `dnb_admin`, `dnb_adminwrite`, `dnb_adminapi` and `dnb_worker` hold
**no** EXECUTE on `mt_audit_write`. An HTTP caller can therefore *cause* an
audit row by performing an audited act; it cannot *write* one. That is what
makes the record trustworthy rather than merely present.

The writer refuses a NULL or blank actor, and refuses an `actor_kind` outside
`principal | staff | system`. An unattributable act is not auditable, so it is
not permitted.

### 1.3 The actor

Four functions gained a required `p_actor text`; three already carried one:

| Function | Actor parameter | Signature changed |
|---|---|---|
| `mt_device_register` | `p_staged_by` (existing) | no |
| `mt_device_assign` | `p_actor` (**new**) | **yes** |
| `mt_device_set_state` | `p_actor` (**new**) | **yes** |
| `mt_device_set_secret` | `p_actor` (**new**) | **yes** |
| `mt_device_set_desired` | `p_actor` (**new**) | **yes** |
| `mt_device_set_wan` | `p_by` (existing) | no |
| `mt_customer_create` | `p_created_by` (existing) | no |

The actor is a **parameter**, supplied by the Admin identity boundary. It is
deliberately **not** read from a session GUC, because a GUC is something an HTTP
caller can set — which is the forgery the requirement exists to prevent. A test
plants `app.actor` and `dn.actor` as `staff:mallory` and asserts the audit row
still records the argument.

The four old signatures are **dropped**, not left beside the new ones. Leaving
them callable would leave an unaudited path to the identical mutation, which is
worse than no fix because it would look like a fix.

### 1.4 What is deliberately withheld from the detail

| Function | Recorded | Withheld |
|---|---|---|
| `device.registered` | serial, model, state | `wg_pubkey`, `tunnel_ip` |
| `device.secret_rotated` | username | the sealed secret |
| `device.desired_set` | nothing (`{}`) | the whole free-form document |
| `device.assigned` | from/to customer and site | — |

This follows **D-2**: what no Admin screen may read back does not go into the
audit log in the first place.

---

## 2. W-2 — the customer/site invariant, as a constraint

### 2.1 The invariant

```
device.site_id IS NULL  OR  device.customer_id = that site's customer_id
```

### 2.2 Why a constraint and not a check inside the function

The measured defect (`docs/73` §1.1) was never that `mt_device_assign()` was
wrong. It was that `dnb_app` bypassed the function entirely with a direct
`UPDATE`. A check inside the function would have left that path open. The
requirement was explicit: *the final security boundary must not depend on
callers voluntarily using the function.*

```sql
ALTER TABLE mt_sites  ADD CONSTRAINT mt_sites_id_customer_key UNIQUE (id, customer_id);
ALTER TABLE mt_devices ADD CONSTRAINT mt_devices_site_customer_fkey
  FOREIGN KEY (site_id, customer_id) REFERENCES mt_sites (id, customer_id);
ALTER TABLE mt_devices ADD CONSTRAINT mt_devices_site_needs_customer
  CHECK (site_id IS NULL OR customer_id IS NOT NULL);
```

`MATCH SIMPLE` skips the check when *any* key column is NULL, so a row with a
site and no customer would slip past the foreign key entirely. The `CHECK`
closes that. `MATCH FULL` would close it too, but it breaks site deletion —
measured in `docs/73` M6.

Site deletion still behaves: the existing single-column FK's `ON DELETE SET
NULL` clears `site_id` and leaves `customer_id` intact. Asserted by execution,
not assumed.

### 2.3 No backfill

Existing rows are **not** touched and no ownership is inferred from any
conflicting row. The constraints are added `VALID` because the development and
test schema has zero violations (`docs/74` §2). **On a database that has any,
this migration must be preceded by the production census** (`docs/79`) and an
explicit decision on each violation class (`docs/74` §7).

`mt_voucher_batches.site_id NOT NULL` remains an **open schema decision**
(`docs/78` §4.2). This increment does not touch it.

---

## 3. W-3 — `dnb_adminwrite`

```
dnb_adminwrite   LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS
  ├─ EXECUTE on exactly the seven approved functions
  ├─ ZERO table privileges — not INSERT, UPDATE, DELETE, and not even SELECT
  ├─ no default privileges pending
  ├─ member of no other role, so it inherits nothing
  └─ no CREATE on schema public
```

Because it holds no table DML at all, it **cannot** mutate business state except
through a function that writes its own audit row. The audit is therefore a
property of the connection, not of the caller's restraint.

`dnb_admin` keeps migration 015's blanket grants. `docs/84` F-3 records why they
are not revoked here: that needs its own dependency audit. A test asserts they
are still present, so a later accidental revoke is caught rather than discovered.

`Database::adminWrite()` is the PHP factory. It is not yet used by any route.

---

## 4. Tests — 109 new assertions

| Group | Requirement | Assertions |
|---|---|---|
| W-1.1 | a successful mutation creates exactly the expected audit event | 21 |
| W-1.1 | all seven functions audit — none left out | 1 |
| W-1.2 | a failed mutation creates no committed audit event | 5 |
| W-1.3 | audit and mutation commit or roll back together | 3 |
| W-1.4 | the actor cannot be forged by a request parameter | 11 |
| W-1.5 | the audit log remains append-only | 8 |
| W-2.1 | a same-customer assignment succeeds | 2 |
| W-2.2 | a cross-customer assignment fails | 2 |
| W-2.3 | unassigned stock stays valid where the lifecycle requires it | 5 |
| W-2.4 | a direct table UPDATE cannot create a cross-customer pairing | 8 |
| W-2.5 | `dnb_app` cannot bypass the invariant | 2 |
| W-2.6 | the Admin write path cannot bypass it either | 3 |
| W-3 | `dnb_adminwrite` holds EXECUTE on the seven and nothing else | 15 |
| W-3 | it can reach no other function, and no arbitrary SQL | 2 |
| W-3 | `dnb_admin` keeps the privileges it already had | 4 |
| W-3 | the unaudited signatures are gone, not merely superseded | 4 |
| W-3 | no Admin write route is bound, and none may forge an actor | 13 |
| | **total** | **109** |

**Suite: 1228 assertions, all green** (1118 before; +109 new, +1 from the audit
isolation test below).

### 4.1 Two tests that were wrong before they were right

**The audit isolation test.** `tests/test_audit.php` asserted that customer A
saw exactly **one** audit row. After W-1, onboarding writes its own row inside
`mt_customer_create`, so seeding A is itself an audited act and A sees two. A
test that still expected one would have been asserting that the fix is absent.
Rewritten to assert both rows by name, and to assert isolation by target rather
than by count.

**The direct-UPDATE test.** The first version attempted the bypass as the
database **owner**. Under FORCE RLS the owner's `UPDATE` matches **zero rows**
and therefore raises nothing — it would have passed while proving only that RLS
hid the row. This is the silent no-op recorded in `docs/73` §1.2, reproduced by
its own author. Rewritten to attempt the bypass as the **BYPASSRLS inspector**,
preceded by a positive control asserting the session really reaches the row
(`1 row updated`). If a constraint stops a BYPASSRLS session, it stops anyone.

A third guard was vacuous rather than wrong: `glob('src/**/*.php')` matches one
directory level and missed four nested files. Replaced with a recursive scan
plus a meta-assertion stating how many files it covered.

---

## 5. Write function inventory — after this migration

### 5.1 Audited (the seven, plus the writer)

| Function | Owner | Audits |
|---|---|---|
| `mt_device_register(text,text,text,text,text,text)` | `dnb_def_prov` | yes |
| `mt_device_assign(uuid,uuid,uuid,text,text)` | `dnb_def_prov` | yes |
| `mt_device_set_state(uuid,text,text)` | `dnb_def_prov` | yes |
| `mt_device_set_secret(uuid,text,text,text)` | `dnb_def_prov` | yes |
| `mt_device_set_desired(uuid,jsonb,text)` | `dnb_def_prov` | yes |
| `mt_device_set_wan(uuid,text,text)` | `dnb_def_prov` | yes |
| `mt_customer_create(text,text)` | `dnb_def_prov` | yes |
| `mt_audit_write(uuid,text,…,jsonb)` | `dnb_def_audit` | (is the writer) |

### 5.2 Additional state-changing definer functions found — NOT changed

The instruction was to audit any additional state-changing definer function
discovered during implementation. Eleven were found. **None was changed**, and
each is classified rather than quietly folded into scope:

| Function | Owner | Class | Why it was not changed |
|---|---|---|---|
| `mt_voucher_redeem(text)` | `dnb_def_net` | **business state** | **This is a genuine gap.** A voucher moves `unused → active` with no audit row. It is neither Admin nor provisioning: it is the redemption path, which is Decision 1 (Model B) territory and frozen. Changing it needs its own authorisation. Its actor is not a staff identity, so the row would be `actor_kind='system'`, and the raw voucher code must never enter the detail. **Recorded as finding F-7.** |
| `mt_auth_issue_code(text,text,interval)` | `dnb_def_auth` | authentication | Customer login, not Admin/provisioning. High frequency and PII-adjacent; auditing it is a separate decision (D-3 scope). |
| `mt_auth_verify_code(text,text)` | `dnb_def_auth` | authentication | as above |
| `mt_auth_create_session(uuid,uuid,text,interval)` | `dnb_def_auth` | authentication | as above |
| `mt_auth_revoke_token(text)` | `dnb_def_auth` | authentication | as above |
| `mt_intent_claim(text,interval,integer)` | `dnb_def_work` | worker/system | Queue mechanics, not business state. Intents already carry their own state history. |
| `mt_intent_expire_overdue()` | `dnb_def_work` | worker/system | as above |
| `mt_sessions_reap(interval)` | `dnb_def_work` | worker/system | as above |
| `mt_session_account(text,…)` | `dnb_def_net` | accounting ingest | RADIUS accounting; auditing every packet would flood the log. |
| `mt_uplink_record(uuid,bigint,bigint,integer)` | `dnb_def_work` | telemetry | Sampling, not an act by an actor. |
| `mt_uplink_prune(interval)` | `dnb_def_work` | telemetry | as above |

### 5.3 Caller-written audit that remains

Six call sites in `src/Api/Routes.php` still write their audit rows from the
route, on the **customer principal** path (plan created/updated/retired, voucher
issued/revoked, session disconnect requested). They are the same F-1 pattern.
They were not changed because they are the customer API, not the Admin write
boundary, and moving them means changing the plan/voucher/session services too.
**Recorded as finding F-8**, for the D-3 audit-scope decision.

---

## 6. Remaining hardware gaps — unchanged by this increment

`docs/84` §5 stands in full. Nothing here brought any hardware operation closer:

| Operation | Delivery case exists? |
|---|---|
| device provision (push desired config) | yes — `RouterOsDelivery` |
| voucher revoke at the router | **no** |
| session disconnect | **no** |
| reboot | **no** |
| reset to defaults | **no** |
| reprovision | **no** |
| arbitrary config push | **no** |

Per the standing instruction, no button may be exposed for an operation whose
delivery case does not exist, and **a queued intent must never be reported as
successful delivery**. F6-B remains gated behind
`DN_ALLOW_REAL_BINDINGS=yes-f6b-authorized`.

---

## 7. Collateral: audit tools now referencing old signatures

Eight historical evidence tools under `tools/audit/` call the four dropped
signatures: `s1_s2_probe.php`, `f5_f8.php`, `f_remaining.php`,
`n10_candidates.php`, `n10_candidates2.php`, `n10_candidates3.php`,
`n10_candidates4.php`, and `p1_device_site_ownership.php`.

They are **not** in the test suite and were **not** repaired. They record what
was measured at the time they ran; rewriting them would edit the evidence.
`s1_s2_probe.php` repair was already a separate task (`docs/65` §15) and stays
one.

---

## 8. Open decisions after this increment

| Ref | Decision | State |
|---|---|---|
| **W-4** | Staff identity provider | **OPEN.** `DenyAllIdentity` remains the production binding. No staff credential store, no password table, no fake session. |
| **W-5** | Missing product operations — suspended state, customer edit, site edit, admin site creation, router rename semantics | **OPEN.** Recorded as product gaps; none invented. |
| **W-6** | Missing delivery cases (§6) | **OPEN.** |
| **F-7** | `mt_voucher_redeem` writes no audit row | **NEW, OPEN.** Redemption path; needs its own authorisation. |
| **F-8** | Six caller-written audit sites on the customer API | **NEW, OPEN.** Belongs to D-3. |
| **D-3** | Audit scope — which acts are auditable at all | OPEN |
| **D-4** | Telemetry / entitlements exposure | OPEN |
| **Q2** | One physical MikroTik serving multiple sites | OPEN |
| — | `dnb_site_nas` cardinality | OPEN |
| — | `mt_voucher_batches.site_id NOT NULL` | OPEN schema decision |
| — | Production census | **REQUIRED before any production migration** (`docs/79`) |
| — | F6-B real bindings | NOT AUTHORIZED |

---

## 9. What this does not authorise

- Binding any Admin write route or button.
- Applying migration 020 to production. The census comes first, and the W-2
  constraints assume zero existing violations — which is established for the
  development schema only.
- Revoking `dnb_admin`'s privileges.
- Auditing, changing or touching `mt_voucher_redeem` or the auth functions.
- Any change to production FreeRADIUS, production PostgreSQL, Domain A, or the
  Starlink HotSpot system.
