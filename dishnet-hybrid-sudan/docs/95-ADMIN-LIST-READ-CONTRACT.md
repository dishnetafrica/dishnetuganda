# 95 — Admin list read contract: decision packet

**Status:** DECISION PACKET. **Documentation only.** No code, schema, migration,
projection or architecture decision changed. Suite unchanged at **1,488
assertions green**. Baseline `a83dd10`.

**Nothing is chosen here.** Page size, cursor format, filter semantics, sort
semantics and count strategy are presented as decisions, not answers, because
the existing architecture supplies no evidence for any of them.

**Scope correction.** The brief names nine projections. There are **ten**:
`mt_admin_voucher_batches()` is also an unbounded list and is included, since
excluding it would leave one screen on a different contract.

---

## 1. Three measured findings that constrain every answer below

### 1.1 The current ordering is not stable — and one case is already degenerate

Eight of the ten projections order by a **non-unique** column. Only
`mt_admin_services()` carries a tiebreaker (`, s.id`).

| Projection | ORDER BY | Unique? |
|---|---|---|
| customers | `c.name` | no |
| sites | `s.name` | no |
| routers | `d.serial` | **yes** — `mt_devices_serial_key` |
| plans | `p.created_at DESC` | no |
| vouchers | `v.created_at DESC` | no |
| voucher_batches | `b.created_at DESC` | no |
| sessions | `s.started_at DESC` | no |
| intents | `i.created_at DESC` | no |
| audit | `a.at DESC` | no |
| services | `s.started_at DESC NULLS LAST, s.id` | **yes** |

A non-unique sort has **no defined row order** between two executions.
Offset pagination over it can skip and duplicate rows; cursor pagination on the
key alone is impossible.

**Vouchers are already degenerate, measured:**

```
17 vouchers, 3 distinct created_at values
   8 rows sharing one timestamp
   5 rows sharing one timestamp
   4 rows sharing one timestamp
```

`issueBatch()` writes a batch in one transaction and `now()` is **transaction**
time, so an entire batch shares a timestamp. A 500-voucher batch is one tie
group of 500 with arbitrary internal order.

The other tables show distinct values **in the simulator only**, because their
rows were written one at a time. That is not evidence about production: a burst
of audit rows or sessions produces ties by construction.

**Every table has a UNIQUE primary key on `id`,** so `(sort_key, id)` is
available as a stable tiebreaker everywhere. That is a fact, not a decision.

### 1.2 Not one index serves an Admin list order

```
EXPLAIN SELECT id FROM mt_audit_log ORDER BY at DESC LIMIT 50;
  Limit
    ->  Sort  (Sort Key: at DESC)
          ->  Seq Scan on mt_audit_log
```

Every index on these tables is **tenant-leading** — `(customer_id, at DESC)`,
`(customer_id, state, created_at DESC)`, `(customer_id, name)` — because the
**customer** API was the design target. The Admin projections read estate-wide
under `USING (true)`, so none of those indexes applies.

**Consequence:** adding `LIMIT` alone yields a bounded page *after* a full scan
and sort of the table. Pagination without index work fixes the payload and not
the query.

### 1.3 The house has no pagination precedent to follow

```php
VoucherService::list()   … ORDER BY created_at DESC LIMIT ?
SessionService::live()   … ORDER BY started_at DESC LIMIT ?
UplinkRepository::recent()… ORDER BY at DESC LIMIT ?
```

A caller-supplied cap, no offset, no cursor, no page token. **The house has
"bounded", never "paginated".** There is no existing cursor format, page-size
default or count strategy to be consistent with — which is precisely why §4
leaves them open.

---

## 2. The fourteen points

### 2.1 Answers that are the same for all ten

| # | Point | Answer |
|---|---|---|
| 3 | Pagination required? | **Yes, for all ten.** Nine have no bound at all; none can be argued to stay unbounded at estate scale |
| 6 | Cursor vs offset | **Cursor, on evidence.** Offset over a non-unique order skips and duplicates (§1.1), and offset cost grows with depth on a table that is already seq-scanned (§1.2). **The cursor's format remains a decision (§4)** |
| 9 | Stable ordering | **Mandatory, and mostly absent today.** `(sort_key, id)` is available everywhere. Changing eight `ORDER BY` clauses alters the projections' observable output, so it is a **boundary change** |
| 11 | Customer/site scoping | Estate-wide by design — `dnb_def_admin` reads under `USING (true)` (migration 019), reachable only through these functions. **Scoping is a filter here, never a security control.** Tenant isolation is RLS on the customer path and must not be re-implemented as an Admin filter argument |
| 12 | One contract for simulator and production? | **Yes.** The simulator runs the real Domain-B paths and the real projections; a second contract would make the prototype prove nothing |
| 14 | Can the existing projections support it without changing the security boundary? | **Yes for bounding and ordering** — arguments and `ORDER BY` do not alter the column allowlist, the owner role or the grants. **No for filtering and sorting**, which introduce caller-supplied values into the function body and need §4-D/E decided first |

### 2.2 Per projection — the points that differ

`max` = maximum possible result today (unbounded ⇒ whole table). Growth is the
honest shape of the table, not a forecast.

| Projection | Max today | Growth | Natural filters | Natural sorts | Security note |
|---|---|---|---|---|---|
| **customers** | all customers | slow, bounded by the business | `status`, name search | name, created_at | name search is a `LIKE` on a tenant-identifying field; harmless to staff who already read the estate |
| **sites** | all sites | ~customers × sites | `customer_id`, name search | name, created_at | as above |
| **routers** | all devices | ~sites × routers | `customer_id`, `site_id`, `state`, serial/model search | serial *(already unique)*, state, created_at | **`state` is the one operational filter that exists today** |
| **plans** | all plans | slow | `customer_id`, `site_id`, `active` | name, created_at, price | none |
| **vouchers** | **all vouchers — the largest table by orders of magnitude** | batches of hundreds per site per period | `customer_id`, `site_id`, `plan_id`, `batch_id`, `state` | created_at, state, expires_at | **`code` must never become a filter argument.** A code-equality filter would be a redemption oracle inside Admin (Decision 3) |
| **voucher_batches** | all batches | ~vouchers ÷ batch size | `customer_id`, `site_id`, `plan_id`, `state` | created_at | none |
| **sessions** | all sessions ever | **fastest-growing operationally** — one row per guest connection, forever | `customer_id`, `state`, `voucher_id`, date range | started_at, bytes | **`device_id` filter is meaningless** — `mt_session_account` never sets it (`docs/91` §4) |
| **intents** | all intents | ~provisioning events | `customer_id`, `state`, `kind` | created_at, state | `payload`/`last_error` are withheld (D-2) and must not become filter arguments either |
| **audit** | **all audit rows, forever** | every audited act; append-only, never deleted | `customer_id`, `actor`, `action`, `target_id`, date range | at | `detail` is withheld (D-2); the same rule applies |
| **services** | all services | ~customers | `customer_id`, `kind`, `status` | started_at | none |

**The two that force the decision** are `audit` and `sessions`: both are
append-only and unbounded in time, and both are read estate-wide with no index
that fits.

### 2.3 Point 13 — security concerns caused by filtering and sorting

Four, and they are the reason filtering is not a free extension:

1. **A filter on a withheld column re-exposes it.** `code`, `payload`,
   `last_error`, `detail`, `radius_username` are withheld by D-2 and Decision 3.
   A caller who may filter `code = ?` and observe a row count has read the code
   one bit at a time. **Rule: a column that is not in the projection's output
   must not be a filter argument.**
2. **A sort on a withheld column leaks its ordering.** Sorting by a hidden field
   discloses relative values. **Rule: sortable ⊆ returned.**
3. **Free-text search must be a bounded operation.** An unanchored `LIKE '%…%'`
   over an unindexed estate-wide table is a denial-of-service primitive against
   the Admin API. Whether search is prefix-only, trigram-indexed or rejected is
   a decision, not a detail.
4. **Filter arguments enter a `SECURITY DEFINER` body.** These functions run as
   `dnb_def_admin`. Arguments must be **typed parameters used as values**, never
   interpolated into SQL — the discipline `AdminReader` already enforces at the
   PHP seam (allowlist + arity + uuid shape) and which must be preserved on the
   SQL side.

### 2.4 Point 10 — total count

Presented as a decision (§4-F), with the evidence:

- a `COUNT(*)` over an estate-wide unindexed table is **a second full scan** per
  page;
- the UI uses counts today — Overview tiles, `head(title, rows.length)`, HotSpot
  voucher counts — all derived from having fetched everything, which is exactly
  what bounding removes;
- so bounding the lists **silently breaks every count on the panel** unless a
  count strategy is decided with it. This is the coupling most likely to be
  missed.

---

## 3. The minimum common list contract

What can be shared without forcing ten resources into one shape.

**Shared — the envelope:**

```
request   page_size            (bounded by a server maximum)
          cursor               (opaque; absent = first page)
response  items[]              the projection's existing allowlist, unchanged
          next_cursor          null when exhausted
```

Plus two invariants that belong to every resource:

- **stable total order** — the resource's sort key plus `id`;
- **sortable ⊆ returned, filterable ⊆ returned** (§2.3 rules 1–2).

**Not shared — per resource:**

- which fields are filterable and sortable (§2.2);
- the default sort — `audit` wants newest-first; `routers` wants serial;
- whether free-text search exists at all.

**Deliberately not in the envelope:** a total count (§4-F), and any
`customer_id` "scope" that could be mistaken for an authorization argument
(§2.1 point 11).

---

## 4. A — Architectural decisions required

None of these has an evidence-based answer. Each is a choice.

| Ref | Decision | Why it cannot be derived |
|---|---|---|
| **A-1** | **Default page size** | No house precedent. `VoucherService` caps at 200 for a *customer's own* vouchers; the Admin estate is a different population. Neither UI density nor operator workflow has been measured |
| **A-2** | **Maximum page size** | Same. The relevant bound is what the Admin API may serialise without becoming a resource-exhaustion surface, and that has not been measured |
| **A-3** | **Cursor format** | No cursor exists in this codebase. Opaque-encoded keyset vs signed token vs raw `(sort_key, id)` pair have different disclosure properties: a raw pair reveals the sort key of the last row, which for `audit` is a timestamp and for `vouchers` is a batch boundary |
| **A-4** | **Filter semantics** | Exact match, prefix, range, set membership, null handling — each has a different index requirement and a different disclosure profile (§2.3) |
| **A-5** | **Sort semantics** | Which fields, which directions, whether the client may choose, and whether an unknown sort field is an error or a silent default |
| **A-6** | **Count strategy** | Exact count, estimate from `pg_class.reltuples`, "has more" boolean, or none. §2.4: whichever is chosen, the panel's existing counts depend on it |
| **A-7** | **Changing eight `ORDER BY` clauses is a boundary change** | It alters observable output of approved projections. Same class of approval as migration 021 |
| **A-8** | **Whether filter/sort arguments may enter the projections at all**, or belong in a separate layer | The projections are a deliberately narrow, allowlisted contract. Adding arguments widens it. The alternative — a sibling set of functions — doubles the surface |
| **A-9** | **Index strategy for estate-wide reads** | §1.2: every index is tenant-leading. Estate-wide `(sort_key, id)` indexes would be new database objects on tables the Admin only reads |

**My reading, offered and not taken:** A-7 and A-9 are the load-bearing pair.
Stable ordering without indexes gives correctness at the cost of a sort on every
page; indexes without stable ordering gives speed with wrong pages. They should
be decided together, and before A-1 through A-6, which are comparatively easy
once the shape is fixed.

## 5. B — Implementation work (after A)

1. Add `(sort_key, id)` tiebreakers to the ten projections — **A-7**.
2. Add the estate-wide indexes — **A-9**.
3. Add `page_size` + `cursor` arguments, or the sibling functions — **A-8**.
4. Extend `AdminReader`'s allowlist to carry each projection's arity and
   argument shape; it currently validates arity and uuid form, and would need
   to validate page size and cursor shape by the same discipline.
5. Extend the route layer to read the two query parameters and return
   `next_cursor`.
6. Update `plugin.json` — the manifest declares the served surface and a test
   asserts they match.
7. Tests: stable order under ties *(the voucher batch of §1.1 is the natural
   fixture)*, no row skipped or duplicated across pages, page-size clamping,
   a filter on a withheld column refused, a sort on a withheld column refused.

## 6. C — UI work (after B)

Search and filter controls on the seven flat lists; cursor paging affordance;
**and re-sourcing every count the panel shows today**, which §2.4 breaks.

## 7. D — Hardware / F6-B dependent

**None.** Pagination, filtering, sorting and indexing are entirely control-plane
concerns and touch nothing behind the hardware gate. This work can proceed to
completion while F6-B stays closed.

---

## 8. What this packet did not do

Implemented nothing. No pagination, no projection, no index, no schema change,
no code change. Chose no page size, cursor format, filter semantics, sort
semantics or count strategy. Changed no architecture decision. No production
contact, no Domain A contact. F6-B untouched. Suite unchanged at **1,488
assertions green**.
