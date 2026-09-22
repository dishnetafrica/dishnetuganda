# 81 — Decisions: staff identity boundary, and Reseller is not a tenant

**Status: DECISION RECORD (approved 2026-09-22).** Both decisions are recorded
and implemented as a **boundary**. No production system was modified. No staff
credential store was created.

---

## Decision 8 — Staff identity is a separate boundary — **CLOSED**

> DishNet staff authenticate through their **own** identity boundary.
> `mt_principals` is **not** reused for staff, and staff are **not** a second
> customer tenant.

```
  DishNet Staff  →  Staff Identity  →  Admin Web    →  Admin API   →  Domain B
  Customer       →  Customer Principal  →  Customer PWA  →  /api/v1/me/*
```

### 8.1 What is implemented

`Dn\Admin\AdminIdentityPort` — a port answering *"who is this staff member and
what may they do?"* Nothing more. The **concrete identity provider is
deliberately absent**: it may later be an existing DishNet identity system
rather than another password database, and that choice is not made here.

The only binding shipped is **`DenyAllIdentity`**, which refuses every request.
The admin API is therefore complete, tested and **unreachable in any
deployment** until a provider is chosen and bound.

### 8.2 What is explicitly NOT implemented

- **No staff credential store.** No password column, no staff table, no OTP path
  for staff. The customer authenticator (`mt_auth_*`) is **not** reachable from
  the admin surface, and a test asserts it.
- **No staff session.** Sessions belong to whatever provider is selected.
- **No migration.** Staff identity adds no schema in F6-A.

### 8.3 The four roles

| Role | Scope |
|---|---|
| **Admin** | full control-plane administration |
| **NOC / Operator** | routers, provisioning, sessions, intents, support operations |
| **Sales** | customers, sites, plans, vouchers, sales |
| **Support** | customer / service / session visibility, limited actions |

Roles are **capabilities**, not tenancy. A role never narrows *which customers*
a staff member sees — it narrows *what they may do*. That distinction is what
keeps staff roles from becoming a second tenant hierarchy.

### 8.4 Still required before Admin Web is reachable

**The DishNet staff identity provider.** Until then `DenyAllIdentity` stands and
Admin Web has nothing to authenticate against.

---

## Decision 9 — Reseller is **not** a tenant — **CLOSED**

> **Reseller is removed from the role matrix.** It is not implemented as a
> tenant or a tenant-like security scope.

docs/42 §4 carried a **Reseller** column whose rows described a tenant-like
scope — *"Own business only"*, *"Assigned only"*, *"Own batches only"*. That
contradicts the settled position:

> The ISP/operator hierarchy is a business-model input, **not a tenant layer**.
> Do not build one from it. (`CLAUDE.md`; docs/68 §2.5, §2.6)

Implementing "own business only" would have created exactly the second tenant
hierarchy that position forbids — quietly, through a role matrix rather than
through an architecture decision.

### 9.1 Not deleted — deferred as a commercial model

Reseller/agent management is **not ruled out**. It is recorded as a **future
commercial / organizational model requiring its own decision**, with evidence,
if DishNet later sells through resellers. What is ruled out is arriving at it by
accident.

### 9.2 The security model for F6-A

```
  DishNet
    ├── Staff                 (capabilities: Admin · NOC · Sales · Support)
    │     └── sees the estate — customers → sites → routers
    └── Customers             (tenants — RLS, server-derived identity)
          └── Customer Operators   ← a future CUSTOMER-side role model, if needed
```

**Staff are not tenants. Customers are.** One tenant hierarchy, as frozen.

### 9.3 Where the role matrix is superseded

docs/42 §4 remains the UX authority for **Admin**, **Operations/Technician** and
the areas covered. Its **Reseller column is superseded by this decision** and is
not implemented. `StaffRole` has no `reseller` case; a test asserts it.

---

## Consequence recorded — the estate-read privilege

Staff read **across** customers. Every tenant table has
`FORCE ROW LEVEL SECURITY` with `USING (customer_id = mt_current_customer())`,
so an estate read returns **nothing** without a privilege path of its own — and
the right answer is emphatically **not** to disable RLS or hand staff a
context-free bypass.

**This is a distinct privilege decision.** It is not taken here. The admin read
path is implemented behind the same deny-all boundary, and the database
privileges it would need are **not granted**, so no estate read can succeed
today even if a provider were bound. It is listed as the next admin-side
decision.
